<?php

defined('ABSPATH') || exit;

class HST_Date
{
    private static $months = [
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    ];

    public static function fa_digits($value)
    {
        return strtr((string) $value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }

    public static function en_digits($value)
    {
        return strtr((string) $value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    }

    public static function gregorian_to_jalali($gy, $gm, $gd)
    {
        $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy = (int) $gy;
        $gm = (int) $gm;
        $gd = (int) $gd;
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100) + (int)(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * (int)($days / 12053));
        $days %= 12053;
        $jy += 4 * (int)($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + (int)($days / 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + (int)(($days - 186) / 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }

    public static function jalali_to_gregorian($jy, $jm, $jd)
    {
        $jy = (int) $jy + 1595;
        $jm = (int) $jm;
        $jd = (int) $jd;
        $days = -355668 + (365 * $jy) + ((int)($jy / 33) * 8) + (int)((($jy % 33) + 3) / 4) + $jd;
        if ($jm < 7) {
            $days += ($jm - 1) * 31;
        } else {
            $days += (($jm - 7) * 30) + 186;
        }
        $gy = 400 * (int)($days / 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * (int)(--$days / 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * (int)($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $sal_a = [0,31,($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0) ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
        for ($gm = 1; $gm <= 12 && $gd > $sal_a[$gm]; $gm++) {
            $gd -= $sal_a[$gm];
        }
        return [$gy, $gm, $gd];
    }

    public static function format($date, $format = 'Y/m/d H:i', $empty = '—')
    {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return $empty;
        }
        $timestamp = is_numeric($date) ? (int) $date : strtotime((string) $date);
        if (!$timestamp) {
            return $empty;
        }
        $gy = (int) wp_date('Y', $timestamp);
        $gm = (int) wp_date('m', $timestamp);
        $gd = (int) wp_date('d', $timestamp);
        [$jy, $jm, $jd] = self::gregorian_to_jalali($gy, $gm, $gd);
        $replacements = [
            'Y' => sprintf('%04d', $jy),
            'y' => substr((string) $jy, -2),
            'm' => sprintf('%02d', $jm),
            'n' => (string) $jm,
            'd' => sprintf('%02d', $jd),
            'j' => (string) $jd,
            'F' => self::$months[$jm] ?? '',
            'H' => wp_date('H', $timestamp),
            'i' => wp_date('i', $timestamp),
            's' => wp_date('s', $timestamp),
        ];
        $out = '';
        $chars = preg_split('//u', $format, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars as $ch) {
            $out .= array_key_exists($ch, $replacements) ? $replacements[$ch] : $ch;
        }
        return self::fa_digits($out);
    }

    public static function today($format = 'Y/m/d')
    {
        return self::format(current_time('timestamp'), $format);
    }

    public static function to_gregorian_date($date)
    {
        $date = trim(self::en_digits((string) $date));
        $date = str_replace(['/', '.', ' '], '-', $date);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            if ($year < 1700) {
                [$gy, $gm, $gd] = self::jalali_to_gregorian($year, $month, $day);
                return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
            }
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        return '';
    }

    public static function to_gregorian_datetime($datetime)
    {
        $datetime = trim(self::en_digits((string) $datetime));
        if ($datetime === '') {
            return null;
        }
        $datetime = str_replace('T', ' ', $datetime);
        if (!preg_match('/^(\d{4}[\/\-.]\d{1,2}[\/\-.]\d{1,2})(?:\s+(\d{1,2}:\d{2})(?::\d{2})?)?$/', $datetime, $m)) {
            $ts = strtotime($datetime);
            return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
        }
        $date = self::to_gregorian_date($m[1]);
        if (!$date) {
            return null;
        }
        $time = $m[2] ?? '00:00';
        return $date . ' ' . $time . ':00';
    }
}