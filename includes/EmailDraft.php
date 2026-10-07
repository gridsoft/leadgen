<?php

/**
 * Final touches every outreach draft gets, whatever the AI wrote:
 *  - the portfolio is always in, as the fixed two-line block
 *      You can see my portfolio here:
 *      https://dmmbs.com/?ref=<slug>
 *    the AI's own wording of it is replaced, and it's added before the sign-off if missing;
 *  - a bullet list always has a lead-in line ending in ":";
 *  - the sign-off is just the name, followed by the fixed DMMBS / opt-out FOOTER.
 * Applied when a draft is stored, when it is shown and right before it is sent.
 *
 * Drafts use one bit of formatting: **bold**. toHtml() renders it (and "* " bullets)
 * for the email the app sends; toPlain() strips it for Gmail / mail-app / copy.
 */
class EmailDraft {
    public const PORTFOLIO_URL = 'https://dmmbs.com/';
    public const PORTFOLIO_LEAD = 'You can see my portfolio here:';
    private const SIGN_OFFS = 'best regards|kind regards|warm regards|regards|best|thanks|thank you|many thanks|cheers|sincerely';
    private const BULLET = '/^\s*[-•*]\s+\S/u';

    public const LIST_INTRO = 'Here is some of the work I have built and delivered for clients:';
    public const NEXT_LIST_INTRO = 'I have also delivered:';

    /** Fixed footer under the name in every email: business + address, and the opt-out line. */
    public const FOOTER = "DMMBS · Jane Sandanski 110 Skopje Macedonia\n"
        . "Not relevant for you? Just reply \"no thanks\" and I won't contact you again.";

    public static function finalize(string $body, ?string $refSlug): string {
        $body = str_replace(self::FOOTER, '', str_replace("\r\n", "\n", $body));
        $body = self::ensurePortfolio(self::introduceLists(self::removeContactLine($body)), $refSlug);
        // removeContactLine() dropped everything under the name (including an earlier footer),
        // so the footer is always exactly once, at the very end.
        return rtrim($body) . "\n\n" . self::FOOTER . "\n";
    }

    /**
     * A bullet list must be introduced by a line ending in ":" — otherwise it reads
     * like a list of buzzwords rather than delivered work. Missing intros get
     * LIST_INTRO (first list) or NEXT_LIST_INTRO (later ones).
     */
    public static function introduceLists(string $body): string {
        $lines = explode("\n", $body);
        $out = [];
        $listsSeen = 0;
        foreach ($lines as $i => $line) {
            $isBullet = preg_match(self::BULLET, $line) === 1;
            $prevIsBullet = $i > 0 && preg_match(self::BULLET, $lines[$i - 1]) === 1;
            if ($isBullet && !$prevIsBullet) {
                $lead = self::lastNonEmpty($out);
                if (substr(rtrim((string) $lead), -1) !== ':') {
                    if ($out && trim(end($out)) !== '') {
                        $out[] = '';
                    }
                    $out[] = $listsSeen === 0 ? self::LIST_INTRO : self::NEXT_LIST_INTRO;
                }
                $listsSeen++;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /** The two-line portfolio block for an agency. */
    public static function portfolioBlock(?string $refSlug): string {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $refSlug)), '-');
        return self::PORTFOLIO_LEAD . "\n" . self::PORTFOLIO_URL . ($slug !== '' ? "?ref=$slug" : '');
    }

    /**
     * The sign-off is "Best regards," plus the name — nothing below it. Drops any
     * contact line the AI wrote under the name (it has even filled one with the
     * agency's own email and phone), plus stray old {EMAIL}/{PHONE}/{LINKEDIN} placeholders.
     */
    public static function removeContactLine(string $body): string {
        $body = preg_replace('/^.*\{(EMAIL|PHONE|LINKEDIN)\}.*$\n?/mu', '', $body);
        if (preg_match_all('/^[ \t]*(?:' . self::SIGN_OFFS . ')[ \t]*[,!.]?[ \t]*$/imu', $body, $m, PREG_OFFSET_CAPTURE)) {
            $signOff = end($m[0]);
            $after = substr($body, $signOff[1] + strlen($signOff[0]));
            // Keep the first non-empty line after the sign-off (the name), drop the rest.
            if (preg_match('/^\s*\n?([^\n]*\S[^\n]*)/u', $after, $name)) {
                $body = substr($body, 0, $signOff[1]) . rtrim($signOff[0]) . "\n" . trim($name[1]);
            }
        }
        return rtrim($body) . "\n";
    }

    /**
     * Exactly one portfolio block. A line that is only the portfolio mention (the link,
     * or "…portfolio…: link") is replaced by the block — the first one — or removed, along
     * with a lead-in line just above it ("You can see my portfolio here:"). A mention inside
     * a longer paragraph is cut out of it. With no mention left, the block goes in as its
     * own paragraph above the sign-off ("Best regards," …).
     */
    public static function ensurePortfolio(string $body, ?string $refSlug): string {
        $block = self::portfolioBlock($refSlug);
        $placed = false;
        $out = [];

        foreach (explode("\n", $body) as $text) {
            if (stripos($text, 'dmmbs.com') === false) {
                $out[] = $text;
                continue;
            }
            $sentences = preg_split('/(?<=[.!?])\s+(?=\S)/u', trim($text));
            $others = array_values(array_filter($sentences, fn($s) => stripos($s, 'dmmbs.com') === false));
            if ($others) {
                $out[] = implode(' ', $others); // keep the rest of the paragraph
                continue;
            }
            // The whole line is the mention: drop its lead-in, put the block here (once).
            $lead = self::lastNonEmpty($out, $leadIndex);
            if ($lead !== null && preg_match('/(portfolio|my work|projects|see .* here)[^.]*:\s*$/i', $lead)) {
                array_splice($out, $leadIndex, 1);
            }
            if (!$placed) {
                $out[] = $block;
                $placed = true;
            }
        }
        $body = implode("\n", $out);
        if ($placed) {
            return $body;
        }

        if (preg_match_all('/^[ \t]*(?:' . self::SIGN_OFFS . ')[ \t]*[,!.]?[ \t]*$/imu', $body, $m, PREG_OFFSET_CAPTURE)) {
            $pos = end($m[0])[1];
            return rtrim(substr($body, 0, $pos)) . "\n\n" . $block . "\n\n" . substr($body, $pos);
        }
        return rtrim($body) . "\n\n" . $block . "\n";
    }

    /** Plain-text version (Gmail / mail app / copy): **bold** markers removed, "* " bullets become "• ". */
    public static function toPlain(string $body): string {
        $body = preg_replace('/\*\*(.+?)\*\*/su', '$1', str_replace("\r\n", "\n", $body));
        return preg_replace('/^(\s*)[*•]\s+/mu', '$1• ', $body);
    }

    /** HTML version for the email the app sends: paragraphs, bullet lists, bold, links; the footer small and grey. */
    public static function toHtml(string $body): string {
        $body = trim(str_replace("\r\n", "\n", $body));
        $footer = null;
        if (substr($body, -strlen(self::FOOTER)) === self::FOOTER) {
            $footer = self::FOOTER;
            $body = rtrim(substr($body, 0, -strlen(self::FOOTER)));
        }
        $inline = function (string $text): string {
            $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            $html = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $html);
            return preg_replace('#(https?://[^\s<]+[^\s<.,;:!?)])#u', '<a href="$1" style="color:#15803d;">$1</a>', $html);
        };

        $parts = [];
        foreach (preg_split('/\n\s*\n/u', $body) as $para) {
            $lines = explode("\n", trim($para));
            $html = '';
            $list = [];
            $text = [];
            $flushText = function () use (&$text, &$html, $inline) {
                if ($text) {
                    $html .= '<p style="margin:0 0 14px;">' . implode('<br>', array_map($inline, $text)) . '</p>';
                    $text = [];
                }
            };
            $flushList = function () use (&$list, &$html, $inline) {
                if ($list) {
                    $html .= '<ul style="margin:0 0 14px;padding-left:22px;">'
                        . implode('', array_map(fn($l) => '<li style="margin:0 0 6px;">' . $inline($l) . '</li>', $list)) . '</ul>';
                    $list = [];
                }
            };
            foreach ($lines as $line) {
                if (preg_match(self::BULLET, $line)) {
                    $flushText();
                    $list[] = preg_replace('/^\s*[-•*]\s+/u', '', $line);
                } else {
                    $flushList();
                    $text[] = $line;
                }
            }
            $flushText();
            $flushList();
            $parts[] = $html;
        }
        if ($footer !== null) {
            $parts[] = '<p style="margin:22px 0 0;font-size:12px;line-height:1.5;color:#777777;">'
                . implode('<br>', array_map(fn($l) => htmlspecialchars($l, ENT_QUOTES, 'UTF-8'), explode("\n", $footer))) . '</p>';
        }
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#222222;">'
            . implode('', $parts) . '</div>';
    }

    /** ref slug for an agency: the AI's ref_slug, else built from the name or domain. */
    public static function refSlug(?string $aiSlug, ?string $agencyName, string $domain): string {
        foreach ([$aiSlug, $agencyName, explode('.', $domain)[0]] as $candidate) {
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $candidate)), '-');
            if ($slug !== '') {
                return $slug;
            }
        }
        return '';
    }

    private static function lastNonEmpty(array $lines, ?int &$index = null): ?string {
        for ($j = count($lines) - 1; $j >= 0; $j--) {
            if (trim((string) $lines[$j]) !== '') {
                $index = $j;
                return $lines[$j];
            }
        }
        $index = null;
        return null;
    }
}
