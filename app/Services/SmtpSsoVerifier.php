<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmtpSsoVerifier
{
    private $host;
    private $port;
    private $ehlo;
    private $timeout;

    public function __construct()
    {
        $this->host = config('services.sso.host');
        $this->port = config('services.sso.port');
        $this->ehlo = config('services.sso.ehlo');
        $this->timeout = config('services.sso.timeout');
    }

    public function verify(string $email, string $password): array
    {
        $email = trim(strtolower($email));
        $password = (string) $password;

        $domain = config('services.sso.allowed_domain');
        if ($email === '' || !str_ends_with($email, '@' . $domain)) {
            return ['status' => 'domain_rejected', 'message' => 'Akses Ditolak. Hanya email @' . $domain . '.'];
        }

        if ($this->shouldBypass()) {
            $devPassword = (string) config('services.sso.dev_password');
            if ($devPassword !== '' && hash_equals($devPassword, $password)) {
                return ['status' => 'bypassed', 'message' => 'Login via bypass lokal (development).'];
            }
            return ['status' => 'invalid_credentials', 'message' => 'Email atau password mail tidak valid.'];
        }

        try {
            return $this->smtpAuthenticate($email, $password);
        } catch (\Throwable $e) {
            Log::warning('SSO SMTP unreachable, fallback lokal aktif', [
                'error' => $e->getMessage(),
                'host' => $this->host . ':' . $this->port,
            ]);

            return ['status' => 'unreachable', 'message' => 'Mail server tidak dapat dijangkau.'];
        }
    }

    private function shouldBypass(): bool
    {
        if (config('services.sso.bypass_local') !== true) {
            return false;
        }

        if (!in_array(app()->environment(), ['local', 'testing', 'dev', 'development'])) {
            Log::warning('SSO_BYPASS_LOCAL aktif di luar environment dev — bypass diabaikan');
            return false;
        }

        return true;
    }

    private function smtpAuthenticate(string $email, string $password): array
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => config('services.sso.verify_tls'),
                'verify_peer_name' => config('services.sso.verify_tls'),
                'allow_self_signed' => false,
            ],
        ]);

        $transport = $this->port === 465 ? 'ssl://' : 'tcp://';

        $socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException("Koneksi gagal: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect($socket, 220, 'greeting');

            $this->command($socket, 'EHLO ' . $this->ehlo);
            $this->expect($socket, 250, 'EHLO');

            if ($this->port !== 465) {
                $this->command($socket, 'STARTTLS');
                $this->expect($socket, 220, 'STARTTLS');

                $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    throw new \RuntimeException('Negosiasi TLS gagal.');
                }

                $this->command($socket, 'EHLO ' . $this->ehlo);
                $this->expect($socket, 250, 'EHLO setelah TLS');
            }

            $this->command($socket, 'AUTH LOGIN');
            $this->expect($socket, 334, 'AUTH LOGIN');

            $this->command($socket, base64_encode($email));
            $this->expect($socket, 334, 'username');

            $this->command($socket, base64_encode($password));
            $response = $this->readResponse($socket);

            $code = (int) substr($response, 0, 3);

            Log::info('SSO SMTP auth response', [
                'code' => $code,
                'raw' => trim($response),
            ]);

            if ($code === 235) {
                Log::info('SSO SMTP auth berhasil', [
                    'host' => $this->host . ':' . $this->port,
                    'email' => $email,
                ]);
                return ['status' => 'verified', 'message' => 'SSO berhasil.'];
            }

            if ($code === 535 || $code === 530 || $code === 531) {
                return ['status' => 'invalid_credentials', 'message' => 'Email atau password mail tidak valid.'];
            }

            throw new \RuntimeException("Mail server menolak auth ({$code}): " . trim($response));
        } finally {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }
    }

    private function command($socket, string $line): void
    {
        $line = str_replace(["\r", "\n"], '', $line);
        if (@fwrite($socket, $line . "\r\n") === false) {
            throw new \RuntimeException('Gagal mengirim perintah ke mail server.');
        }
    }

    private function readResponse($socket): string
    {
        $data = '';
        while (($line = @fgets($socket, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                return $data;
            }
        }
        throw new \RuntimeException('Tidak ada respons dari mail server.');
    }

    private function expect($socket, int $code, string $stage): void
    {
        $response = $this->readResponse($socket);
        $actual = (int) substr($response, 0, 3);
        if ($actual !== $code) {
            throw new \RuntimeException("SMTP {$stage} gagal ({$actual}): " . trim($response));
        }
    }
}