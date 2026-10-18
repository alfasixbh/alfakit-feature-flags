<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

/**
 * `FeatureFlagsEntry` actions run through the field action endpoint. Inside a form the
 * response re-renders the form in place. An infolist has no form to update, so there the
 * browser reloads the page it is on (same host only).
 */
final class PageRefresh
{
    public static function url(): ?string
    {
        $request = request();
        $component = $request->input('component');

        if (! is_string($component) || ! str_starts_with($component, 'field:infolist')) {
            return null;
        }

        $referer = $request->headers->get('referer');

        if (! is_string($referer) || $referer === '') {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, $request->getHost()) === 0 ? $referer : null;
    }
}
