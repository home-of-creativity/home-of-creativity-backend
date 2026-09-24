<?php

namespace App\Support;

final class ShowcaseLogoRules
{
    /**
     * Raster images plus SVG. Do not use the `image` rule — Laravel treats
     * SVG as a non-image because getimagesize() cannot read it.
     *
     * @return list<string>
     */
    public static function file(): array
    {
        return [
            'nullable',
            'file',
            'mimes:jpg,jpeg,png,webp,svg',
            'max:2048',
        ];
    }
}
