<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;

/**
 * The GBPC landing page's "Join the Conference Live" video source — an
 * admin-editable HLS (.m3u8) URL. The static landing page fetches it at load,
 * so an admin can change what streams without the page being re-uploaded.
 */
final class GbpcLiveStream
{
    public const KEY = 'gbpc_live_stream_url';

    public const DEFAULT = 'https://vcpout-lw-wdc-01-sp.ceflixcdn.com/lxp/play2/playlist.m3u8';

    public static function url(): string
    {
        $url = AppSetting::get(self::KEY, self::DEFAULT);

        return '' !== (string) $url ? (string) $url : self::DEFAULT;
    }

    public static function setUrl(string $url): void
    {
        AppSetting::set(self::KEY, $url);
    }
}
