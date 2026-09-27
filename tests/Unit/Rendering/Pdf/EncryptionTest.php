<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering\Pdf;

use App\Document\PrinceOptions;
use App\Rendering\Pdf\Encryption;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EncryptionTest extends TestCase
{
    #[Test]
    public function itIsNullWhenEncryptionIsOff(): void
    {
        self::assertNull(Encryption::fromPrince(PrinceOptions::fromRequest(['encrypt' => false])));
        self::assertNull(Encryption::fromPrince(PrinceOptions::fromRequest([])));
    }

    #[Test]
    public function fortyBitArgumentsEnableWeakCryptoBeforeEncrypting(): void
    {
        $encryption = new Encryption('user', 'owner', 40, allowPrint: true, allowCopy: false, allowAnnotate: true, allowModify: false);

        self::assertSame([
            '--allow-weak-crypto',
            '--encrypt',
            '--user-password=user',
            '--owner-password=owner',
            '--bits=40',
            '--print=y',
            '--extract=n',
            '--annotate=y',
            '--modify=n',
            '--',
        ], $encryption->arguments());
    }

    #[Test]
    public function weakCryptoMustComeBeforeEncrypt(): void
    {
        // qpdf only accepts --allow-weak-crypto ahead of --encrypt.
        $arguments = new Encryption('u', 'o', 40, true, true, true, true)->arguments();
        $weakCrypto = array_search('--allow-weak-crypto', $arguments, true);
        $encrypt = array_search('--encrypt', $arguments, true);
        self::assertSame(0, $weakCrypto);
        self::assertIsInt($encrypt);
        self::assertLessThan($encrypt, $weakCrypto);
    }

    #[Test]
    public function oneHundredAndTwentyEightBitArgumentsUseAesAndGranularPermissions(): void
    {
        $encryption = new Encryption('user', 'owner', 128, allowPrint: false, allowCopy: true, allowAnnotate: false, allowModify: true);

        self::assertSame([
            '--encrypt',
            '--user-password=user',
            '--owner-password=owner',
            '--bits=128',
            '--use-aes=y',
            '--accessibility=y',
            '--print=none',
            '--extract=y',
            '--annotate=n',
            '--form=n',
            '--assemble=y',
            '--modify-other=y',
            '--',
        ], $encryption->arguments());
    }

    #[Test]
    public function the128BitPrintPermissionIsFullOrNone(): void
    {
        $allowed = new Encryption('u', 'o', 128, true, true, true, true)->arguments();
        self::assertContains('--print=full', $allowed);

        $denied = new Encryption('u', 'o', 128, false, true, true, true)->arguments();
        self::assertContains('--print=none', $denied);
    }

    #[Test]
    public function theArgumentListAlwaysEndsWithADoubleDash(): void
    {
        // The trailing -- stops qpdf reading the input file name as an option.
        $fortyBit = new Encryption('u', 'o', 40, true, true, true, true)->arguments();
        $oneTwentyEight = new Encryption('u', 'o', 128, true, true, true, true)->arguments();
        self::assertSame('--', end($fortyBit));
        self::assertSame('--', end($oneTwentyEight));
    }

    #[Test]
    public function itMapsPrinceOptionsToPermissions(): void
    {
        $encryption = Encryption::fromPrince(PrinceOptions::fromRequest([
            'encrypt' => true,
            'key_bits' => 40,
            'user_password' => 'secret',
            'owner_password' => 'boss',
            'disallow_print' => true,
            'disallow_copy' => false,
        ]));

        self::assertInstanceOf(Encryption::class, $encryption);
        self::assertSame('secret', $encryption->userPassword);
        self::assertSame('boss', $encryption->ownerPassword);
        self::assertSame(40, $encryption->keyBits);
        self::assertFalse($encryption->allowPrint); // disallow_print: true
        self::assertTrue($encryption->allowCopy); // disallow_copy: false
    }

    #[Test]
    public function aMissingUserPasswordBecomesAnEmptyOpenPassword(): void
    {
        $encryption = Encryption::fromPrince(PrinceOptions::fromRequest(['encrypt' => true, 'owner_password' => 'boss']));
        self::assertInstanceOf(Encryption::class, $encryption);
        self::assertSame('', $encryption->userPassword);
        self::assertSame('boss', $encryption->ownerPassword);
    }

    #[Test]
    public function aMissingOwnerPasswordIsGenerated(): void
    {
        // Without an owner password anyone could lift the restrictions, so one is invented.
        $encryption = Encryption::fromPrince(PrinceOptions::fromRequest(['encrypt' => true]));
        self::assertInstanceOf(Encryption::class, $encryption);
        self::assertSame('', $encryption->userPassword);
        self::assertNotSame('', $encryption->ownerPassword);
        self::assertGreaterThanOrEqual(20, \strlen($encryption->ownerPassword));
    }
}
