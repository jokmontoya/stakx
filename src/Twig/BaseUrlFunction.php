<?php

namespace allejo\stakx\Twig;

use allejo\stakx\Object\FrontMatterObject;
use Twig_Environment;

class BaseUrlFunction
{
    public function __invoke (Twig_Environment $env, $assetPath)
    {
        $globals = $env->getGlobals();

        if ($assetPath instanceof FrontMatterObject)
        {
            $assetPath = $assetPath->getPermalink();
        }

        // @TODO 1.0.0 Remove support for 'base' as it's been deprecated
        $base = (array_key_exists('base', $globals['site'])) ? $globals['site']['base'] : $globals['site']['baseurl'];

        $baseURL = (empty($base)) ? '/' : '/' . trim($base, '/') . '/';
        $url     = ltrim($assetPath, '/');

        return ($baseURL . $url);
    }

    public static function get ()
    {
        return new \Twig_SimpleFunction('url', new self(), array(
            'needs_environment' => true
        ));
    }
}