<?php

declare(strict_types=1);

namespace App\Rendering\Pdf;

use App\Document\PrinceOptions;

/**
 * Prince's encryption options, as qpdf arguments.
 */
final readonly class Encryption
{
    public function __construct(
        public string $userPassword,
        public string $ownerPassword,
        public int $keyBits,
        public bool $allowPrint,
        public bool $allowCopy,
        public bool $allowAnnotate,
        public bool $allowModify,
    ) {
    }

    public static function fromPrince(PrinceOptions $options): ?self
    {
        if (!$options->encrypt) {
            return null;
        }

        return new self(
            userPassword: $options->userPassword ?? '',
            // Without an owner password anyone could lift the restrictions.
            ownerPassword: $options->ownerPassword ?? rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='),
            keyBits: $options->keyBits,
            allowPrint: !$options->disallowPrint,
            allowCopy: !$options->disallowCopy,
            allowAnnotate: !$options->disallowAnnotate,
            allowModify: !$options->disallowModify,
        );
    }

    /**
     * @return list<string>
     */
    public function arguments(): array
    {
        $yes = static fn (bool $allowed): string => $allowed ? 'y' : 'n';
        $encrypt = ['--encrypt', "--user-password={$this->userPassword}", "--owner-password={$this->ownerPassword}"];
        if (40 === $this->keyBits) {
            // RC4 40-bit, as Prince writes it: weak, but some clients still ask for it.
            $arguments = ['--allow-weak-crypto', ...$encrypt];
            array_push(
                $arguments,
                '--bits=40',
                '--print='.$yes($this->allowPrint),
                '--extract='.$yes($this->allowCopy),
                '--annotate='.$yes($this->allowAnnotate),
                '--modify='.$yes($this->allowModify),
            );
        } else {
            $arguments = $encrypt;
            array_push(
                $arguments,
                '--bits=128',
                '--use-aes=y',
                '--accessibility=y',
                '--print='.($this->allowPrint ? 'full' : 'none'),
                '--extract='.$yes($this->allowCopy),
                '--annotate='.$yes($this->allowAnnotate),
                '--form='.$yes($this->allowAnnotate),
                '--assemble='.$yes($this->allowModify),
                '--modify-other='.$yes($this->allowModify),
            );
        }
        $arguments[] = '--';

        return $arguments;
    }
}
