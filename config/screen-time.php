<?php

return [
    /*
     * How much screen time is allowed per day, in minutes.
     *
     * During the school year, weekdays get a smaller allowance than weekends.
     * Over the summer holidays every day gets the weekend allowance.
     */
    'limits' => [
        'weekend_minutes' => 180,
        'weekday_minutes' => 150,
        'holiday_months' => [7, 8],
    ],

    /*
     * The window each day's timeline chart covers, as hours of the day.
     */
    'day_start_hour' => 6,
    'day_end_hour' => 22,

    /*
     * How many days (including today) the dashboard charts show.
     */
    'days_shown' => 7,

    /*
     * Durations offered as quick-add buttons, in minutes.
     */
    'quick_durations' => [15, 30, 60],

    /*
     * Durations offered to extend a session that is already running, in minutes.
     */
    'extend_durations' => [5, 10, 15],
];
