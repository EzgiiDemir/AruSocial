<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;

class LegalController extends Controller
{
    public function privacy(): Response
    {
        return $this->page(
            'Gizlilik Politikası / KVKK Aydınlatma Metni',
            base_path('../docs/legal/privacy.md')
        );
    }

    public function terms(): Response
    {
        return $this->page(
            'Kullanım Şartları',
            base_path('../docs/legal/terms.md')
        );
    }

    private function page(string $title, string $path): Response
    {
        $body = File::exists($path)
            ? e(File::get($path))
            : 'Bu metin henüz yayınlanmadı.';
        $html = '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.e($title).' — ARUVERSE</title>'
            .'<style>body{font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 20px;line-height:1.5;color:#1c1e22}pre{white-space:pre-wrap}</style>'
            .'</head><body><h1>'.e($title).'</h1><pre>'.$body.'</pre></body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
