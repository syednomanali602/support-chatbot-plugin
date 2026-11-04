=== Support Chatbot ===
Contributors: syednomanali
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

A FAQ-driven support chatbot with smart matching and a "talk to a person" handoff. Runs entirely on your own site: no third-party AI, chat or analytics service, no API keys.

== Description ==

Visitors type a question; the bot finds the best-matching FAQ you wrote and answers with it. When it isn't sure it offers "did you mean..." choices, and when nothing fits it offers to connect the visitor with a person. Those requests are saved in WordPress and emailed to you.

It is NOT generative AI. It can only say what you have written, which also means it can never invent a wrong price or promise. The more real phrasings you add to each FAQ, the better it gets, and the Unanswered list shows you exactly which FAQs to write next.

= Features =

* Smart matching with typo tolerance, word-weighting, English and Roman Urdu / other-language phrasings
* Asks "did you mean..." instead of guessing when two FAQs are close
* Handoff form (name, email or phone, message) with recent chat attached; saved in wp-admin and emailed
* Dashboard with a live tester that shows why a message matched
* Unanswered-questions list, one click to turn a question into an FAQ
* "Was this helpful?" feedback per answer
* Light / dark, follows a site toggle that adds a "light" class to the html element
* Keyboard and screen-reader friendly; respects reduced-motion
* Rate limiting and a honeypot; no nonce, so it keeps working behind page caching
* Personal-data export/erase integration, automatic log retention

= Opening the chat from your own buttons =

Add `data-dvcb-open` to any element, or link to `#dvcb-open`.

= Button links inside answers =

`/services/` (a page on this site), `#some-id` (an element on the same page), `#dvcb-handoff` (the chatbot's own form) or a full https:// address.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ and activate it.
2. A few starter FAQs are added on first activation. Review them under Chatbot > FAQs.
3. Chatbot > Settings: set the position (raise "distance from bottom" if you have another floating button), accent colour and where handoff requests are emailed.

== Hooks ==

* `do_action( 'dvcb_handoff_created', $post_id, $data )` after a request is stored
* `apply_filters( 'dvcb_should_render', true )` to hide the chat on specific views
* `apply_filters( 'dvcb_capability', 'manage_options' )` who can manage it
* `apply_filters( 'dvcb_client_ip', $ip )` if you run behind a trusted proxy

== Changelog ==

= 1.0.0 =
* First release.
