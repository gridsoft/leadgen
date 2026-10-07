<?php
require_once __DIR__ . '/../includes/AutoSender.php';

function slovenian_time(string $when): DateTimeImmutable {
    return new DateTimeImmutable($when, new DateTimeZone(AutoSender::TIMEZONE));
}

return [
    'sending window: weekdays 15:00–22:00 Slovenian time only' => function () {
        assert_true(AutoSender::inWindow(slovenian_time('2026-10-07 15:00')), 'Wednesday 15:00');
        assert_true(AutoSender::inWindow(slovenian_time('2026-10-09 21:59')), 'Friday 21:59');
        assert_true(!AutoSender::inWindow(slovenian_time('2026-10-07 14:59')), 'before the window');
        assert_true(!AutoSender::inWindow(slovenian_time('2026-10-07 22:00')), 'after the window');
        assert_true(!AutoSender::inWindow(slovenian_time('2026-10-10 16:00')), 'Saturday');
        assert_true(!AutoSender::inWindow(slovenian_time('2026-10-11 16:00')), 'Sunday');
    },
    'the window is judged in Slovenian time, whatever the server clock says' => function () {
        $utc = new DateTimeImmutable('2026-10-07 13:30', new DateTimeZone('UTC')); // 15:30 in Ljubljana (summer time)
        assert_true(AutoSender::inWindow($utc->setTimezone(new DateTimeZone(AutoSender::TIMEZONE))));
        assert_same('15', AutoSender::now()->setTimestamp($utc->getTimestamp())->format('H'));
    },
    'limits match the plan: 15 a day, +5 a week, at most 40' => function () {
        assert_same([15, 5, 40], [AutoSender::START_LIMIT, AutoSender::STEP, AutoSender::MAX_LIMIT]);
        assert_true(AutoSender::GROW_MAX_BOUNCE_RATE <= 0.03 && AutoSender::PAUSE_BOUNCE_RATE <= 0.05);
    },
];
