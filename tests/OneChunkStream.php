<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Psr\Http\Message\StreamInterface;

/**
 * A request body that behaves like php://input behind a real PSR-7 stack.
 *
 * StreamInterface::read() may return fewer bytes than asked for, and over
 * php://input or a socket it returns one 8 KiB chunk per call. The stream is
 * also not seekable, so whatever one reader consumes is gone for the next.
 *
 * @internal
 */
final class OneChunkStream implements StreamInterface
{
    private const CHUNK = 8192;

    private int $position = 0;

    public function __construct(private readonly string $contents) {}

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->contents);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $chunk = substr($this->contents, $this->position, min($length, self::CHUNK));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $rest = substr($this->contents, $this->position);
        $this->position = strlen($this->contents);

        return $rest;
    }

    public function getMetadata(?string $key = null)
    {
        return null === $key ? [] : null;
    }
}
