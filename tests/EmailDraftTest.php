<?php
require_once __DIR__ . '/../includes/EmailDraft.php';

// How every finalized email ends: the name, a blank line, then the fixed footer.
define('SIGN_OFF_END', "Best regards,\nSlobodan Stevkovski\n\n" . EmailDraft::FOOTER . "\n");
const PORTFOLIO_BLOCK = "You can see my portfolio here:\nhttps://dmmbs.com/?ref=seolevelup";

return [
    'missing portfolio is added as its own paragraph before the sign-off' => function () {
        $out = EmailDraft::finalize("Hi Chris,\n\nI build WordPress sites.\n\nWould a short call work?\n\nBest regards,\nSlobodan Stevkovski", 'seolevelup');
        assert_same("Hi Chris,\n\nI build WordPress sites.\n\nWould a short call work?\n\n" . PORTFOLIO_BLOCK . "\n\n" . SIGN_OFF_END, $out);
    },
    'the AI\'s own two-line portfolio (lead-in + link) becomes the fixed block, in place' => function () {
        $in = "Hi,\n\nI can help.\n\nHere is my work:\nhttps://dmmbs.com/?ref=seo-level-up\n\nWould a call work?\n\nBest regards,\nSlobodan Stevkovski";
        $out = EmailDraft::finalize($in, 'seolevelup');
        assert_contains("I can help.\n\n" . PORTFOLIO_BLOCK . "\n\nWould a call work?", $out);
        assert_not_contains('Here is my work', $out);
        assert_same(1, substr_count($out, 'dmmbs.com'));
    },
    'a one-line portfolio sentence becomes the fixed block' => function () {
        $out = EmailDraft::finalize("Hi,\n\nYou can view my portfolio of over 100 delivered systems here: https://dmmbs.com/?ref=x\n\nBest regards,\nSlobodan Stevkovski", 'seolevelup');
        assert_contains("\n" . PORTFOLIO_BLOCK . "\n", $out);
        assert_not_contains('of over 100', $out);
    },
    'a mention inside a paragraph is cut out; the block goes above the sign-off' => function () {
        $out = EmailDraft::finalize("I love your SEO focus. My portfolio is at dmmbs.com. Happy to start small.\n\nBest regards,\nSlobodan Stevkovski", 'seolevelup');
        assert_contains("I love your SEO focus. Happy to start small.\n\n" . PORTFOLIO_BLOCK . "\n\nBest regards,", $out);
    },
    'two mentions become one' => function () {
        $out = EmailDraft::finalize("Portfolio: https://dmmbs.com/?ref=x\n\nMore at https://dmmbs.com\n\nBest regards,\nSlobodan Stevkovski", 'seolevelup');
        assert_same(1, substr_count($out, 'dmmbs.com'));
    },
    'old signature contact line is removed; the name is followed only by the footer' => function () {
        $out = EmailDraft::finalize("Hi,\n\n" . PORTFOLIO_BLOCK . "\n\nBest regards,\nSlobodan Stevkovski\nhttps://dmmbs.com · {EMAIL} · {PHONE} · {LINKEDIN}", 'seolevelup');
        assert_same(SIGN_OFF_END, substr($out, -strlen(SIGN_OFF_END)));
        assert_not_contains('{EMAIL}', $out);
        assert_same(1, substr_count($out, 'dmmbs.com'), 'the contact line\'s link is not mistaken for the portfolio');
    },
    'any contact line the AI writes under the name is dropped (even with real details)' => function () {
        $in = "Would a call work?\n\nBest regards,\nSlobodan Stevkovski\nhttps://dmmbs.com · hello@alphaefficiency.com · 13123006746 · https://linkedin.com";
        $out = EmailDraft::finalize($in, 'alpha-efficiency');
        assert_same("Would a call work?\n\nYou can see my portfolio here:\nhttps://dmmbs.com/?ref=alpha-efficiency\n\n" . SIGN_OFF_END, $out);
        assert_not_contains('alphaefficiency.com', $out);
    },
    'no sign-off: the block goes at the end, before the footer' => function () {
        assert_contains("Thanks for reading.\n\n" . PORTFOLIO_BLOCK . "\n\n" . EmailDraft::FOOTER, EmailDraft::finalize('Thanks for reading.', 'seolevelup'));
    },
    'ref slug falls back to the agency name, then the domain' => function () {
        assert_same('seolevelup', EmailDraft::refSlug('seolevelup', 'SEO Level Up', 'seolevelup.com'));
        assert_same('seo-level-up', EmailDraft::refSlug(null, 'SEO Level Up!', 'seolevelup.com'));
        assert_same('seolevelup', EmailDraft::refSlug('', null, 'seolevelup.com'));
    },
    'a list straight after an unrelated sentence gets the "delivered" intro' => function () {
        $in = "I help agencies scale without in-house overhead.\n\n- LLM integration and RAG systems\n- Custom WordPress builds";
        assert_contains("overhead.\n\n" . EmailDraft::LIST_INTRO . "\n- LLM integration and RAG systems", EmailDraft::introduceLists($in));
    },
    'the new structure\'s lead-in ("…particularly with:") is left alone' => function () {
        $in = "I can support your team as a flexible white-label engineering resource, particularly with:\n\n* **WordPress / WooCommerce** — custom themes\n* **AI development** — RAG systems";
        assert_same($in, EmailDraft::introduceLists($in));
    },
    'a second list without a lead-in gets "I have also delivered:"' => function () {
        $out = EmailDraft::introduceLists("Intro.\n\n- AI one\n- AI two\n\nAnd more.\n\n- Dev one");
        assert_contains(EmailDraft::LIST_INTRO . "\n- AI one", $out);
        assert_contains("And more.\n\n" . EmailDraft::NEXT_LIST_INTRO . "\n- Dev one", $out);
    },
    'the footer appears exactly once, even with no sign-off or when finalized again' => function () {
        $a = EmailDraft::finalize("Hi,\n\nBest regards,\nSlobodan Stevkovski", 'x');
        $b = EmailDraft::finalize('Just a note without a sign-off.', 'x');
        foreach ([$a, $b, EmailDraft::finalize($a, 'x'), EmailDraft::finalize($b, 'x')] as $out) {
            assert_same(1, substr_count($out, 'Not relevant for you?'));
            assert_same(EmailDraft::FOOTER . "\n", substr($out, -strlen(EmailDraft::FOOTER) - 1), 'footer is the very end');
        }
    },
    'running finalize twice changes nothing' => function () {
        $once = EmailDraft::finalize("Hi,\n\nI can support your team, particularly with:\n\n* **PHP / Laravel** — APIs\n\nBest regards,\nSlobodan Stevkovski\nhttps://dmmbs.com · {EMAIL}", 'seolevelup');
        assert_same($once, EmailDraft::finalize($once, 'seolevelup'));
    },
    'plain text: bold markers removed, "* " bullets become "• "' => function () {
        $plain = EmailDraft::toPlain("particularly with:\n\n* **WordPress / WooCommerce** — custom themes\n\nI'm happy to start with one **small paid task**.");
        assert_same("particularly with:\n\n• WordPress / WooCommerce — custom themes\n\nI'm happy to start with one small paid task.", $plain);
    },
    'HTML: bullets become a list, bold is bold, links are links, footer is small, text is escaped' => function () {
        $html = EmailDraft::toHtml(EmailDraft::finalize("Hi <Chris> & team,\n\nI can support your team, particularly with:\n\n* **AI development** — RAG systems\n* **PHP / Laravel** — APIs\n\nBest regards,\nSlobodan Stevkovski", 'seolevelup'));
        assert_contains('Hi &lt;Chris&gt; &amp; team,', $html);
        assert_contains('<ul', $html);
        assert_contains('<li style="margin:0 0 6px;"><strong>AI development</strong> — RAG systems</li>', $html);
        assert_contains('<a href="https://dmmbs.com/?ref=seolevelup"', $html);
        assert_contains('Best regards,<br>Slobodan Stevkovski', $html);
        assert_contains('font-size:12px', $html, 'footer styled small');
        assert_not_contains('**', $html);
    },
];
