<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Matches a visitor's message against the FAQ list.
 *
 * No external service and no learned model: it is a weighted token-overlap
 * score with typo tolerance. That is deliberately predictable — the bot can
 * only ever say something an admin wrote, and the Dashboard tester shows
 * exactly why a given message matched.
 *
 * How a score is built, per FAQ phrase (the question itself, or any
 * alternative phrasing the admin added):
 *
 *   coverage of the visitor's words  (how much of what they said is explained)
 *   coverage of the phrase's words   (how much of the phrase they touched)
 *
 * Each word is weighted by IDF, so a word that appears in every FAQ
 * ("project") counts for little and a distinctive one ("laravel") for a lot.
 * A word the visitor used that no FAQ knows about gets an average weight, so
 * long off-topic messages dilute the score instead of riding on one shared
 * word.
 */
class DVCB_Matcher {

	/** Weight of a query word no FAQ has ever used, relative to the average known word. */
	const UNKNOWN_FACTOR = 1.0;

	/** Exponent on query coverage in the final score (phrase coverage gets 1 minus this). */
	const Q_EXP = 0.5;

	private static $stop = null;

	/**
	 * Words that carry no topic. English plus common Roman-Urdu fillers
	 * (hai, kya, kitna, lagega ...). Question words are in here on purpose:
	 * "how long" and "how much" are told apart by "long" / "much".
	 */
	private static function stopwords() {
		if ( null === self::$stop ) {
			$list = 'a an the is are was were be been am do does did i me my we our you your it its to of for in on at by with and or '
				. 'can could would should will shall please pls plz hi hello hey there this that these those so if as about from into up '
				. 'tell give want need like get know have has had any some ok okay how what which who whom why when where '
				. 'hai hain hy ha ka ki ke ko se mein me main par pe per aur ya kya kia kaise kese kaisay kab kahan kidhar kyun kyu kon kaun '
				. 'mujhe mjhe mera meri mere hum hamara hamari hamare aap ap apka apki apke tum ye yeh wo woh isko usko ho hota hoti hote '
				. 'hoga hogi honge karna karni karo kar kare karein karen chahiye chahye chahta chahti bata batao batain bataiye sakta sakti '
				. 'sakte tha thi ek koi bhi toh na kitna kitni kitne lagega lagta lagti aayega aayegi milega milegi dikhao dikhaye dikhayein';
			self::$stop = array_flip( explode( ' ', $list ) );
		}
		return self::$stop;
	}

	/* ------------------------------------------------------------------ */
	/* Text handling                                                       */
	/* ------------------------------------------------------------------ */

	public static function normalize( $text ) {
		$t = DVCB_Util::lower( (string) $text );
		if ( function_exists( 'remove_accents' ) ) {
			$t = remove_accents( $t );
		}
		// "don't" -> "dont", not "don t".
		$t = str_replace( array( "'", "\xE2\x80\x99" ), '', $t );
		$t = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $t );
		$t = preg_replace( '/\s+/u', ' ', (string) $t );
		return trim( (string) $t );
	}

	/**
	 * Light suffix stripping so "services" meets "service" and
	 * "developing" meets "develop". Fuzzy matching below covers the rest.
	 */
	public static function stem( $t ) {
		$len = strlen( $t );
		if ( $len < 4 || ! DVCB_Util::is_ascii( $t ) ) {
			return $t;
		}
		if ( $len > 5 && 'ies' === substr( $t, -3 ) ) {
			return substr( $t, 0, -3 ) . 'y';
		}
		if ( $len > 6 && 'ing' === substr( $t, -3 ) ) {
			return substr( $t, 0, -3 );
		}
		if ( $len > 5 && 'ed' === substr( $t, -2 ) ) {
			return substr( $t, 0, -2 );
		}
		$tail = substr( $t, -2 );
		if ( 's' === substr( $t, -1 ) && 'ss' !== $tail && 'us' !== $tail && 'is' !== $tail ) {
			return substr( $t, 0, -1 );
		}
		return $t;
	}

	/**
	 * Distinct, stemmed, stop-word-free tokens.
	 */
	public static function tokenize( $text ) {
		$norm = self::normalize( $text );
		if ( '' === $norm ) {
			return array();
		}
		$stop = self::stopwords();
		$out  = array();
		foreach ( explode( ' ', $norm ) as $word ) {
			if ( '' === $word || isset( $stop[ $word ] ) || DVCB_Util::len( $word ) < 2 ) {
				continue;
			}
			$word = self::stem( $word );
			if ( ! in_array( $word, $out, true ) ) {
				$out[] = $word;
			}
		}
		return $out;
	}

	/**
	 * Similarity of two tokens, 0..1.
	 *
	 * Fuzzy rules only apply to ASCII tokens of 4+ chars; shorter words
	 * ("app", "seo", "erp") must match exactly, or "app" would match "apt".
	 *
	 * $fuzzy is switched off by the caller for words the FAQs already use.
	 * Edit-distance matching is for typos, and a word that is in the
	 * vocabulary is not a typo: without this, "react" matched "reach" and a
	 * question about React was answered with the contact details.
	 */
	public static function token_sim( $a, $b, $fuzzy = true ) {
		if ( $a === $b ) {
			return 1.0;
		}
		$la = strlen( $a );
		$lb = strlen( $b );
		$min = min( $la, $lb );
		if ( $min < 4 || ! DVCB_Util::is_ascii( $a ) || ! DVCB_Util::is_ascii( $b ) ) {
			return 0.0;
		}
		// One is the beginning of the other: develop / development.
		if ( 0 === strpos( $a, $b ) || 0 === strpos( $b, $a ) ) {
			return 0.75;
		}
		if ( ! $fuzzy || $a[0] !== $b[0] || $la > 60 || $lb > 60 ) {
			return 0.0;
		}
		$allowed = ( max( $la, $lb ) >= 8 ) ? 2 : 1;
		$dist    = levenshtein( $a, $b );
		if ( $dist <= $allowed ) {
			return 1 === $dist ? 0.85 : 0.75;
		}
		return 0.0;
	}

	/* ------------------------------------------------------------------ */
	/* Index                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array $faqs List of [ 'id' => int, 'question' => string,
	 *                              'variants' => string[], 'priority' => int ].
	 * @return array Index to hand to score() / decide().
	 */
	public static function build_index( array $faqs ) {
		$phrases = array();
		$df      = array();
		$prio    = array();

		foreach ( $faqs as $faq ) {
			$id            = (int) $faq['id'];
			$prio[ $id ]   = max( 0, min( 10, (int) $faq['priority'] ) );
			$all_phrases   = array_merge( array( $faq['question'] ), (array) $faq['variants'] );
			$faq_tokens    = array();

			foreach ( $all_phrases as $phrase ) {
				$phrase = trim( (string) $phrase );
				if ( '' === $phrase ) {
					continue;
				}
				$tokens = self::tokenize( $phrase );
				foreach ( $tokens as $t ) {
					$faq_tokens[ $t ] = true;
				}
				$phrases[] = array(
					'faq'    => $id,
					'norm'   => self::normalize( $phrase ),
					'tokens' => $tokens,
				);
			}
			foreach ( array_keys( $faq_tokens ) as $t ) {
				$df[ $t ] = isset( $df[ $t ] ) ? $df[ $t ] + 1 : 1;
			}
		}

		$n   = max( 1, count( $faqs ) );
		$idf = array();
		foreach ( $df as $t => $count ) {
			$idf[ $t ] = log( ( $n + 1 ) / $count ) + 1;
		}
		$avg = $idf ? array_sum( $idf ) / count( $idf ) : 1.0;

		return array(
			'phrases'  => $phrases,
			'idf'      => $idf,
			'avg_idf'  => $avg,
			'priority' => $prio,
			'count'    => count( $faqs ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Scoring                                                             */
	/* ------------------------------------------------------------------ */

	protected static function weight( $token, $index ) {
		// A word no FAQ has ever used is noise more often than signal (filler,
		// names, typos), so it weighs less than an average known word, but
		// still enough that padding a message with them lowers the score.
		return isset( $index['idf'][ $token ] ) ? $index['idf'][ $token ] : static::UNKNOWN_FACTOR * $index['avg_idf'];
	}

	/**
	 * @return array [ faq_id => score 0..1 ] best first.
	 */
	public static function score( $query, array $index ) {
		$qn = self::normalize( $query );
		$qt = self::tokenize( $query );
		if ( '' === $qn || empty( $index['phrases'] ) ) {
			return array();
		}

		// Only words the FAQs have never used may be treated as typos.
		$fuzzy = array();
		foreach ( $qt as $q ) {
			$fuzzy[ $q ] = ! isset( $index['idf'][ $q ] );
		}

		$best = array();
		foreach ( $index['phrases'] as $p ) {
			if ( $p['norm'] === $qn ) {
				$s = 1.0;
			} elseif ( empty( $qt ) || empty( $p['tokens'] ) ) {
				continue;
			} else {
				$sum_q = 0.0;
				$hit_q = 0.0;
				foreach ( $qt as $q ) {
					$w      = static::weight( $q, $index );
					$sum_q += $w;
					$top    = 0.0;
					foreach ( $p['tokens'] as $t ) {
						$sim = self::token_sim( $q, $t, $fuzzy[ $q ] );
						if ( $sim > $top ) {
							$top = $sim;
							if ( 1.0 === $top ) {
								break;
							}
						}
					}
					$hit_q += $w * $top;
				}

				$sum_p = 0.0;
				$hit_p = 0.0;
				foreach ( $p['tokens'] as $t ) {
					$w      = static::weight( $t, $index );
					$sum_p += $w;
					$top    = 0.0;
					foreach ( $qt as $q ) {
						$sim = self::token_sim( $q, $t, $fuzzy[ $q ] );
						if ( $sim > $top ) {
							$top = $sim;
							if ( 1.0 === $top ) {
								break;
							}
						}
					}
					$hit_p += $w * $top;
				}

				$cov_q = $sum_q > 0 ? $hit_q / $sum_q : 0.0;
				$cov_p = $sum_p > 0 ? $hit_p / $sum_p : 0.0;
				// Geometric, not additive: a one-word phrase such as "projects" has
				// 100% phrase coverage for ANY message containing that word, which
				// under a weighted sum was enough to float a long off-topic message
				// into "did you mean". Multiplying means both sides must be decent.
				$s     = ( $cov_q > 0 && $cov_p > 0 ) ? pow( $cov_q, static::Q_EXP ) * pow( $cov_p, 1 - static::Q_EXP ) : 0.0;
			}

			if ( ! isset( $best[ $p['faq'] ] ) || $s > $best[ $p['faq'] ] ) {
				$best[ $p['faq'] ] = $s;
			}
		}

		// Priority only breaks ties between otherwise-similar FAQs.
		foreach ( $best as $id => $s ) {
			if ( $s > 0 && ! empty( $index['priority'][ $id ] ) ) {
				$best[ $id ] = min( 1.0, $s + 0.004 * $index['priority'][ $id ] );
			}
		}

		arsort( $best );
		return $best;
	}

	/**
	 * Turns scores into a decision.
	 *
	 * @param float $confident  0..1: answer directly at or above this.
	 * @param float $suggest_min 0..1: offer "did you mean" at or above this.
	 * @return array {
	 *   type: 'answer' | 'suggest' | 'fallback',
	 *   faq_id: int (answer only),
	 *   score: float (best score),
	 *   candidates: array of [ id, score ] (top 3 above suggest_min),
	 *   ambiguous: bool
	 * }
	 */
	public static function decide( $query, array $index, $confident, $suggest_min ) {
		$scores = self::score( $query, $index );

		$cands = array();
		foreach ( $scores as $id => $s ) {
			if ( $s < $suggest_min || count( $cands ) >= 3 ) {
				break;
			}
			$cands[] = array( (int) $id, round( $s, 3 ) );
		}

		if ( empty( $cands ) ) {
			return array(
				'type'       => 'fallback',
				'faq_id'     => 0,
				'score'      => $scores ? round( reset( $scores ), 3 ) : 0.0,
				'candidates' => array(),
				'ambiguous'  => false,
			);
		}

		$top = $cands[0][1];

		if ( $top >= $confident ) {
			// Two FAQs scoring almost the same means the message could be
			// either; asking is cheaper than answering the wrong one.
			$close = isset( $cands[1] ) && $cands[1][1] >= $top - 0.05 && $top < 0.92;
			if ( ! $close ) {
				return array(
					'type'       => 'answer',
					'faq_id'     => $cands[0][0],
					'score'      => $top,
					'candidates' => $cands,
					'ambiguous'  => false,
				);
			}
			return array(
				'type'       => 'suggest',
				'faq_id'     => 0,
				'score'      => $top,
				'candidates' => $cands,
				'ambiguous'  => true,
			);
		}

		return array(
			'type'       => 'suggest',
			'faq_id'     => 0,
			'score'      => $top,
			'candidates' => $cands,
			'ambiguous'  => false,
		);
	}
}
