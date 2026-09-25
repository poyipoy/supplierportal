<?php

namespace Tests\Unit\Support;

use App\Support\BankTransferMapping;
use PHPUnit\Framework\TestCase;

class BankTransferMappingTest extends TestCase
{
    /**
     * @dataProvider bcaBankNameProvider
     */
    public function test_bca_resolves_to_internal_transfer(string $bankName): void
    {
        $result = BankTransferMapping::resolve($bankName);
        $this->assertNotNull($result, "BCA variant '{$bankName}' should resolve");
        $this->assertSame('BCA', $result['transfer_type']);
        $this->assertSame('CENAIDJA', $result['sandi_bic']);
    }

    public static function bcaBankNameProvider(): array
    {
        return [
            ['BCA'],
            ['Bank BCA'],
            ['BANK CENTRAL ASIA'],
            ['bca'], // case insensitive
            ['BCA (KCU Karawang)'], // branch suffix
        ];
    }

    /**
     * @dataProvider nonBcaBankNameProvider
     */
    public function test_non_bca_resolves_to_llg(string $bankName, string $expectedBic): void
    {
        $result = BankTransferMapping::resolve($bankName);
        $this->assertNotNull($result, "Bank '{$bankName}' should resolve");
        $this->assertSame('LLG', $result['transfer_type']);
        $this->assertSame($expectedBic, $result['sandi_bic']);
    }

    public static function nonBcaBankNameProvider(): array
    {
        return [
            ['MANDIRI', 'BMRIIDJA'],
            ['Bank Mandiri', 'BMRIIDJA'],
            ['BNI', 'BNINIDJA'],
            ['Bank Negara Indonesia', 'BNINIDJA'],
            ['BRI', 'BRINIDJA'],
            ['CIMB NIAGA', 'BNIAIDJA'],
            ['DANAMON', 'BDINIDJA'],
            ['PERMATA', 'BBBAIDJA'],
            ['BTN', 'BTANIDJA'],
            ['PANIN', 'PINBIDJA'],
            ['OCBC NISP', 'NISPIDJA'],
            ['MAYBANK', 'MABORIDJ'],
            ['MEGA', 'MEGAIDJA'],
            ['SINARMAS', 'SABORIDJ'],
            ['BUKOPIN', 'BBUKIDJA'],
            ['BSI', 'BSMDIDJA'],
        ];
    }

    public function test_unknown_bank_returns_null(): void
    {
        $this->assertNull(BankTransferMapping::resolve('BANK ACME UNKNOWN'));
        $this->assertNull(BankTransferMapping::resolve(''));
        $this->assertNull(BankTransferMapping::resolve('  '));
    }

    public function test_branch_suffix_stripped(): void
    {
        $result = BankTransferMapping::resolve('MANDIRI (Cabang Cikarang)');
        $this->assertNotNull($result);
        $this->assertSame('BMRIIDJA', $result['sandi_bic']);
        $this->assertSame('LLG', $result['transfer_type']);
    }

    public function test_case_insensitivity(): void
    {
        $lower = BankTransferMapping::resolve('bni');
        $upper = BankTransferMapping::resolve('BNI');
        $mixed = BankTransferMapping::resolve('Bni');

        $this->assertNotNull($lower);
        $this->assertNotNull($upper);
        $this->assertNotNull($mixed);
        $this->assertSame($lower['sandi_bic'], $upper['sandi_bic']);
        $this->assertSame($lower['sandi_bic'], $mixed['sandi_bic']);
    }

    public function test_whitespace_trimmed(): void
    {
        $result = BankTransferMapping::resolve('  BCA  ');
        $this->assertNotNull($result);
        $this->assertSame('BCA', $result['transfer_type']);
    }
}
