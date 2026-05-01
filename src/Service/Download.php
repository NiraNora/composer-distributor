<?php

declare(strict_types=1);

namespace PharIo\ComposerDistributor\Service;

use PharIo\ComposerDistributor\Url;
use RuntimeException;
use SplFileInfo;
use function explode;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fwrite;
use function getenv;
use function ltrim;
use function parse_url;
use function preg_replace;
use function stream_context_create;
use function strtolower;
use function trim;

final class Download
{
    private $url;

    public function __construct(Url $url)
    {
        $this->url = $url;
    }

    public function toLocation(SplFileInfo $downloadLocation) : void
    {
        $context = $this->getStreamContext($this->url->toString());
        $source = @fopen($this->url->toString(), 'r',false, $context);
        if ($source === false) {
            throw new RuntimeException(
                'Failed to open download source: ' . $this->url->toString()
            );
        }

        $target = @fopen($downloadLocation->getPathname(), 'w');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException(
                'Failed to open download target: ' . $downloadLocation->getPathname()
            );
        }

        try {
            while (!feof($source)) {
                $chunk = fread($source, 1024);
                if ($chunk === false) {
                    throw new RuntimeException(
                        'Failed to read from download source: ' . $this->url->toString()
                    );
                }
                fwrite($target, $chunk);
            }
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    /**
     * @return resource
     */
    private function getStreamContext(string $targetUrl)
    {
        $proxy = $this->resolveProxyForUrl($targetUrl);

        if (empty($proxy)) {
            return stream_context_create([]);
        }

        $proxy = preg_replace('#^https://#i', 'tls://', $proxy);
        $proxy = preg_replace('#^http://#i', 'tcp://', $proxy);

        $context = [
            'http' => [
                'proxy' => $proxy,
                'request_fulluri' => true,
            ]
        ];

        if (str_starts_with($proxy, 'tls://')) {
            $context['ssl'] = [
                'SNI_enabled' => true,
            ];
        }

        $auth = getenv('HTTP_PROXY_AUTH');
        if (!empty($auth)) {
            $context['http']['header'][] = 'Proxy-Authorization: Basic ' . $auth;
        }

        return stream_context_create($context);
    }

    private function resolveProxyForUrl(string $targetUrl): ?string
    {
        $isHttps = strncasecmp($targetUrl, 'https://', 8) === 0;

        $candidates = $isHttps
            ? ['https_proxy', 'HTTPS_PROXY', 'http_proxy', 'HTTP_PROXY']
            : ['http_proxy', 'HTTP_PROXY'];

        $proxy = '';
        foreach ($candidates as $envName) {
            $proxy = getenv($envName);
            if (!empty($proxy)) {
                break;
            }
        }

        if (empty($proxy)) {
            return null;
        }

        if ($this->urlMatchesNoProxy($targetUrl)) {
            return null;
        }

        return $proxy;
    }

    private function urlMatchesNoProxy(string $targetUrl): bool
    {
        $noProxy = getenv('no_proxy');
        if (empty($noProxy)) {
            $noProxy = getenv('NO_PROXY');
        }
        if (empty($noProxy)) {
            return false;
        }

        $host = parse_url($targetUrl, PHP_URL_HOST);
        if (empty($host)) {
            return false;
        }
        $host = strtolower($host);

        foreach (explode(',', $noProxy) as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }
            if ($entry === '*') {
                return true;
            }

            $entry = ltrim($entry, '.');
            if ($host === $entry || substr($host, -strlen('.' . $entry)) === '.' . $entry) {
                return true;
            }
        }

        return false;
    }
}
