<?php

namespace Tests\Feature;

use App\Support\TrustedProxyList;
use Tests\TestCase;

// P3-7 §13: TRUSTED_PROXIES parsing, isolated from the app boot process so
// its exact behavior for every input shape is pinned down independently of
// bootstrap/app.php.
class TrustedProxyListTest extends TestCase
{
    public function test_empty_or_null_trusts_no_proxy(): void
    {
        $this->assertNull(TrustedProxyList::parse(null));
        $this->assertNull(TrustedProxyList::parse(''));
        $this->assertNull(TrustedProxyList::parse('   '));
    }

    public function test_a_lone_wildcard_trusts_whichever_host_connected(): void
    {
        $this->assertSame('*', TrustedProxyList::parse('*'));
    }

    public function test_a_single_ip_becomes_a_one_item_list(): void
    {
        $this->assertSame(['10.0.0.1'], TrustedProxyList::parse('10.0.0.1'));
    }

    public function test_a_comma_separated_list_is_trimmed_and_split(): void
    {
        $this->assertSame(
            ['10.0.0.1', '10.0.0.2', '172.16.0.0/12'],
            TrustedProxyList::parse(' 10.0.0.1 , 10.0.0.2,172.16.0.0/12 '),
        );
    }

    public function test_stray_commas_do_not_produce_empty_entries(): void
    {
        $this->assertSame(['10.0.0.1'], TrustedProxyList::parse('10.0.0.1,,'));
    }
}
