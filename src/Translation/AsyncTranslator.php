<?php

declare(strict_types=1);

namespace SConcur\Laravel\Translation;

use Illuminate\Translation\Translator;
use InvalidArgumentException;
use SConcur\Context\Context;

/**
 * Coroutine-safe translator.
 *
 * Before bootCompleted(): behaves like the stock Translator.
 * After bootCompleted(): the active locale lives per-coroutine in the context.
 * The $loaded translations cache stays shared — only the locale differs per request.
 *
 * Ported from yangusik/laravel-spawn (AsyncTranslator).
 */
class AsyncTranslator extends Translator
{
    private const string CTX_KEY = 'translator.locale';

    private bool $async = false;

    private string $bootLocale = '';

    public function bootCompleted(): void
    {
        $this->bootLocale = $this->locale;
        $this->async      = true;
    }

    public function setLocale($locale)
    {
        if (!$this->async) {
            parent::setLocale($locale);

            return;
        }

        if (str_contains($locale, '/') || str_contains($locale, '\\')) {
            throw new InvalidArgumentException('Invalid characters present in locale.');
        }

        Context::current()->set(self::CTX_KEY, $locale, replace: true);
    }

    public function getLocale()
    {
        if (!$this->async) {
            return parent::getLocale();
        }

        return Context::current()->find(self::CTX_KEY) ?? $this->bootLocale;
    }

    public function locale()
    {
        return $this->getLocale();
    }

    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        if ($this->async && $locale === null) {
            $locale = $this->getLocale();
        }

        return parent::get($key, $replace, $locale, $fallback);
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        if ($this->async && $locale === null) {
            $locale = $this->getLocale();
        }

        return parent::choice($key, $number, $replace, $locale);
    }

    public function has($key, $locale = null, $fallback = true)
    {
        if ($this->async && $locale === null) {
            $locale = $this->getLocale();
        }

        return parent::has($key, $locale, $fallback);
    }

    protected function localeForChoice($key, $locale)
    {
        if ($this->async && $locale === null) {
            $locale = $this->getLocale();
        }

        return parent::localeForChoice($key, $locale);
    }

    protected function localeArray($locale)
    {
        if ($this->async && $locale === null) {
            $locale = $this->getLocale();
        }

        return parent::localeArray($locale);
    }
}
