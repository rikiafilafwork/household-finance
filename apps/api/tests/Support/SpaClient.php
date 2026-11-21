<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Meniru browser untuk SPA Sanctum: simpan cookie antar request, kirim X-XSRF-TOKEN,
 * dan reset guard + session store tiap request supaya autentikasi benar-benar dibaca dari
 * session cookie, bukan dari state yang tertinggal di memori proses test.
 */
final class SpaClient
{
    public const ORIGIN = 'http://localhost:8080';

    /** @var array<string, string> cookie mentah (sudah terenkripsi) dari response */
    private array $cookies = [];

    public function __construct(private readonly TestCase $test)
    {
        // Sama dengan produksi (SESSION_DRIVER=database): session hanya bisa dipulihkan lewat cookie.
        config(['session.driver' => 'database']);
    }

    public function csrf(): self
    {
        $this->send('GET', '/sanctum/csrf-cookie');

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    public function get(string $uri, array $data = []): TestResponse
    {
        return $this->send('GET', $uri, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    public function post(string $uri, array $data = []): TestResponse
    {
        return $this->send('POST', $uri, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    public function send(string $method, string $uri, array $data = []): TestResponse
    {
        // Tiap request mulai bersih seperti PHP-FPM: tanpa guard / session store sisa request lain.
        app('auth')->forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');

        // Pakai call() langsung (bukan withHeaders/withCookies) supaya state tidak menempel di
        // instance TestCase dan dua SpaClient benar-benar seperti dua browser terpisah.
        $server = [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_REFERER' => self::ORIGIN.'/',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];
        if (isset($this->cookies['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = urldecode($this->cookies['XSRF-TOKEN']);
        }

        $isRead = in_array(strtoupper($method), ['GET', 'HEAD'], true);
        $content = $isRead ? null : (string) json_encode($data);

        $response = $this->test->call($method, $uri, $isRead ? $data : [], $this->cookies, [], $server, $content);

        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }
}
