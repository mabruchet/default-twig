<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Service\PriceList;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Pricing\PriceList\Import\PriceListCsvReader;

/**
 * Keeps the CSV of a price list import between the deposit and its confirmation: in a
 * directory under var/, never reachable from the web root, under a random name that is the
 * only handle the screens carry. The file is deleted when the import is applied or abandoned,
 * and, at the latest, at the next deposit (every file of the same list, and every file older
 * than a day).
 */
final readonly class PriceListImportStorage
{
    private const TOKEN_PATTERN = '/^[0-9a-f]{32}$/';

    private const ACCEPTED_EXTENSIONS = ['csv', 'txt'];

    private const ACCEPTED_MIME_TYPES = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'inode/x-empty'];

    private const MAXIMUM_AGE_SECONDS = 86400;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/price-list-imports')]
        private string $directory,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return string the token of the stored file
     *
     * @throws PriceListRefusedException
     */
    public function store(UploadedFile $file, int $priceListId): string
    {
        if (!$file->isValid()) {
            throw new PriceListRefusedException($this->translator->trans('The file could not be received. Check its size (5 MB at most) and try again.'));
        }

        if ((int) $file->getSize() > PriceListCsvReader::MAX_FILE_BYTES) {
            throw new PriceListRefusedException($this->translator->trans('The file is larger than 5 MB.'));
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = (string) $file->getMimeType();
        if (!\in_array($extension, self::ACCEPTED_EXTENSIONS, true) || !\in_array($mimeType, self::ACCEPTED_MIME_TYPES, true)) {
            throw new PriceListRefusedException($this->translator->trans('The file is not a CSV file.'));
        }

        $this->ensureDirectory();
        $this->purge($priceListId);

        $token = bin2hex(random_bytes(16));
        $file->move($this->directory, $this->name($priceListId, $token));

        return $token;
    }

    /**
     * The stored file of the token, null when the token is not one or the file is gone.
     */
    public function find(int $priceListId, string $token): ?\SplFileInfo
    {
        if (1 !== preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }

        $path = $this->directory.\DIRECTORY_SEPARATOR.$this->name($priceListId, $token);

        return is_file($path) && !is_link($path) ? new \SplFileInfo($path) : null;
    }

    public function discard(int $priceListId, string $token): void
    {
        $file = $this->find($priceListId, $token);
        if (null !== $file) {
            @unlink($file->getPathname());
        }
    }

    /**
     * Deletes the files left by an earlier deposit of the list, and the ones nobody came back for.
     */
    private function purge(int $priceListId): void
    {
        $now = time();
        foreach (glob($this->directory.\DIRECTORY_SEPARATOR.'*.csv') ?: [] as $path) {
            $ofThisList = str_starts_with(basename($path), $priceListId.'-');
            $expired = ($now - (int) filemtime($path)) > self::MAXIMUM_AGE_SECONDS;
            if ($ofThisList || $expired) {
                @unlink($path);
            }
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o700, true);
        }
    }

    private function name(int $priceListId, string $token): string
    {
        return $priceListId.'-'.$token.'.csv';
    }
}
