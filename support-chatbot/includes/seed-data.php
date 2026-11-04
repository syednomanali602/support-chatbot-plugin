<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Starter FAQs added on first activation (only when no FAQs exist yet).
 *
 * These are deliberately generic: they make no claims about prices, products
 * or timelines, and where details would differ per site the answer points the
 * visitor to the "talk to a person" form. They're ordinary FAQs: edit or
 * delete freely.
 *
 * Button URLs: "/path/" resolves against the site URL, "#some-id" jumps to an
 * element on the page, "#dvcb-handoff" opens the chatbot's own "talk to a
 * person" form.
 */
return array(
	array(
		'question' => 'What do you offer?',
		'variants' => array(
			'services',
			'what do you do',
			'what do you provide',
			'what can you help with',
			'products',
			'what do you sell',
			'what services do you offer',
			'kya services dete ho',
			'aap kya kaam karte hain',
		),
		'answer'   => 'We would be happy to tell you more. Leave your details and a team member will get back to you with the right information for what you need.',
		'btn_label' => 'Talk to a person',
		'btn_url'   => '#dvcb-handoff',
		'starter'   => true,
		'priority'  => 5,
	),
	array(
		'question' => 'How much does it cost?',
		'variants' => array(
			'pricing',
			'price',
			'prices',
			'cost',
			'how much',
			'rates',
			'what do you charge',
			'budget',
			'cost estimate',
			'kitna kharcha',
			'price kya hai',
			'kitne paise',
		),
		'answer'   => 'Pricing depends on what you need. Leave your details and a team member will reply with the right figures.',
		'btn_label' => 'Talk to a person',
		'btn_url'   => '#dvcb-handoff',
		'starter'   => true,
		'priority'  => 4,
	),
	array(
		'question' => 'How long does it take?',
		'variants' => array(
			'timeline',
			'how long',
			'how long does it take',
			'delivery time',
			'turnaround',
			'when will it be ready',
			'how fast',
			'how quickly',
			'kitna time lagega',
			'kitna waqt',
		),
		'answer'   => 'It depends on the details. Leave your contact information and a team member will give you a realistic timeline.',
		'btn_label' => 'Talk to a person',
		'btn_url'   => '#dvcb-handoff',
		'starter'   => false,
		'priority'  => 3,
	),
	array(
		'question' => 'How can I contact you?',
		'variants' => array(
			'contact',
			'contact us',
			'contact details',
			'email',
			'phone',
			'phone number',
			'call',
			'reach you',
			'get in touch',
			'rabta',
			'contact kaise karun',
			'number do',
		),
		'answer'   => 'You can leave your name and contact details here and a team member will reply to you.',
		'btn_label' => 'Talk to a person',
		'btn_url'   => '#dvcb-handoff',
		'starter'   => true,
		'priority'  => 6,
	),
	array(
		'question' => 'Is my information kept private?',
		'variants' => array(
			'privacy',
			'is my data safe',
			'data safe',
			'secure',
			'will you share my details',
			'meri information safe hai',
			'mera data safe',
		),
		'answer'   => 'Details you share in this chat are used only to reply to your request.',
		'btn_label' => '',
		'btn_url'   => '',
		'starter'   => false,
		'priority'  => 2,
	),
);
