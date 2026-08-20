<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class StrongPassword implements Rule
{
    // Daftar password umum yang dilarang
    protected $commonPasswords = [
        'password', 'password123', 'password123@', '12345678', '12345678@',
        'qwerty', 'qwerty123', 'admin', 'admin123', 'admin123@',
        'letmein', 'welcome', 'welcome123', 'monkey', 'dragon',
        'master', 'login', 'abc123', '11111111', '1234567890',
        'iloveyou', 'trustno1', 'sunshine', 'princess', 'football',
        'shadow', 'superman', 'michael', 'password1', 'password@',
    ];

    public function passes($attribute, $value)
    {
        // Cek panjang minimal 8
        if (strlen($value) < 8) {
            $this->message = 'Password minimal 8 karakter.';
            return false;
        }

        // Cek huruf kecil
        if (!preg_match('/[a-z]/', $value)) {
            $this->message = 'Password harus mengandung huruf kecil.';
            return false;
        }

        // Cek huruf kapital
        if (!preg_match('/[A-Z]/', $value)) {
            $this->message = 'Password harus mengandung huruf kapital.';
            return false;
        }

        // Cek angka
        if (!preg_match('/[0-9]/', $value)) {
            $this->message = 'Password harus mengandung angka.';
            return false;
        }

        // Cek simbol
        if (!preg_match('/[!@#$%^&*()_+\-=\[\]{}|;:\'",.<>?\/\\\\`~]/', $value)) {
            $this->message = 'Password harus mengandung simbol (contoh: !@#$%).';
            return false;
        }

        // Cek password umum
        if (in_array(strtolower($value), $this->commonPasswords)) {
            $this->message = 'Password terlalu umum dan mudah ditebak.';
            return false;
        }

        return true;
    }

    public function message()
    {
        return $this->message ?? 'Password tidak memenuhi aturan keamanan.';
    }
}