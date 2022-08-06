<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\Profiler;

/**
 * Storage for profiler using files.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class FileProfilerStorage implements ProfilerStorageInterface
{
    protected const MAX_RETENTION_DAYS = 1;

    /**
     * Folder where profiler data are stored.
     */
    private string $folder;

    /**
     * Constructs the file storage using a "dsn-like" path.
     *
     * Example : "file:/path/to/the/storage/folder"
     *
     * @throws \RuntimeException
     */
    public function __construct(string $dsn)
    {
        if (!str_starts_with($dsn, 'file:')) {
            throw new \RuntimeException(sprintf('Please check your configuration. You are trying to use FileStorage with an invalid dsn "%s". The expected format is "file:/path/to/the/storage/folder".', $dsn));
        }
        $this->folder = substr($dsn, 5);

        if (!is_dir($this->folder) && false === @mkdir($this->folder, 0777, true) && !is_dir($this->folder)) {
            throw new \RuntimeException(sprintf('Unable to create the storage directory (%s).', $this->folder));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function find(?string $ip, ?string $url, ?int $limit, ?string $method, int $start = null, int $end = null, string $statusCode = null): array
    {
        $file = $this->getIndexFilename();

        if (!file_exists($file)) {
            return [];
        }

        $file = fopen($file, 'r');
        fseek($file, 0, \SEEK_END);

        $result = [];
        while (\count($result) < $limit && $line = $this->readLineFromFile($file)) {
            $values = str_getcsv($line);
            [$csvToken, $csvIp, $csvMethod, $csvUrl, $csvTime, $csvParent, $csvStatusCode] = $values;
            $csvTime = (int) $csvTime;

            if ($ip && !str_contains($csvIp, $ip) || $url && !str_contains($csvUrl, $url) || $method && !str_contains($csvMethod, $method) || $statusCode && !str_contains($csvStatusCode, $statusCode)) {
                continue;
            }

            if (!empty($start) && $csvTime < $start) {
                continue;
            }

            if (!empty($end) && $csvTime > $end) {
                continue;
            }

            $result[$csvToken] = [
                'token' => $csvToken,
                'ip' => $csvIp,
                'method' => $csvMethod,
                'url' => $csvUrl,
                'time' => $csvTime,
                'parent' => $csvParent,
                'status_code' => $csvStatusCode,
            ];
        }

        fclose($file);

        return array_values($result);
    }

    /**
     * {@inheritdoc}
     */
    public function purge()
    {
        $flags = \FilesystemIterator::SKIP_DOTS;
        $iterator = new \RecursiveDirectoryIterator($this->folder, $flags);
        $iterator = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($iterator as $file) {
            if (is_file($file)) {
                unlink($file);
            } else {
                rmdir($file);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $token): ?Profile
    {
        if (!$token || !file_exists($file = $this->getFilename($token))) {
            return null;
        }

        if (\function_exists('gzcompress')) {
            $file = 'compress.zlib://'.$file;
        }

        if (!$data = unserialize(file_get_contents($file))) {
            return null;
        }

        return $this->createProfileFromData($token, $data);
    }

    /**
     * {@inheritdoc}
     *
     * @throws \RuntimeException
     */
    public function write(Profile $profile): bool
    {
        $file = $this->getFilename($profile->getToken());

        $profileIndexed = is_file($file);
        if (!$profileIndexed) {
            // Create directory
            $dir = \dirname($file);
            if (!is_dir($dir) && false === @mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Unable to create the storage directory (%s).', $dir));
            }
        }

        $profileToken = $profile->getToken();
        // when there are errors in sub-requests, the parent and/or children tokens
        // may equal the profile token, resulting in infinite loops
        $parentToken = $profile->getParentToken() !== $profileToken ? $profile->getParentToken() : null;
        $childrenToken = array_filter(array_map(function (Profile $p) use ($profileToken) {
            return $profileToken !== $p->getToken() ? $p->getToken() : null;
        }, $profile->getChildren()));

        // Store profile
        $data = [
            'token' => $profileToken,
            'parent' => $parentToken,
            'children' => $childrenToken,
            'data' => $profile->getCollectors(),
            'ip' => $profile->getIp(),
            'method' => $profile->getMethod(),
            'url' => $profile->getUrl(),
            'time' => $profile->getTime(),
            'status_code' => $profile->getStatusCode(),
        ];

        $context = stream_context_create();

        if (\function_exists('gzcompress')) {
            $file = 'compress.zlib://'.$file;
            stream_context_set_option($context, 'zlib', 'level', 3);
        }

        if (false === file_put_contents($file, serialize($data), 0, $context)) {
            return false;
        }

        if (!$profileIndexed) {

//            $this->removeOldestProfiles($indexFilename = $this->getIndexFilename());
            // Add to index
            if (false === $file = fopen($indexFilename = $this->getIndexFilename(), 'a')) {
                return false;
            }

            fputcsv($file, [
                $profile->getToken(),
                $profile->getIp(),
                $profile->getMethod(),
                $profile->getUrl(),
                $profile->getTime(),
                $profile->getParentToken(),
                $profile->getStatusCode(),
            ]);
            fclose($file);
        }

        return true;
    }

    // TODO TODO continues, renamming + tests + drop files
    public function removeOldestProfiles(string $indexFilename)
    {
        $minimalProfileTime = (new \DateTime())
            ->modify(sprintf('-%d days', self::MAX_RETENTION_DAYS))
            ->getTimestamp();

        $handle = fopen($indexFilename, 'r');

        $tmpIndexFileName = $indexFilename.'.tmp';
        $beforeTime = true;
        $atLeastOneProfileBeforeTime = false;
        while ($beforeTime && $line = fgets($handle)) {
            $csv = str_getcsv($line);
            $profileTime = (int) $csv[4];

            if ($profileTime > $minimalProfileTime) {
                $beforeTime = false;
                file_put_contents($tmpIndexFileName, $line);
            } else {
                $atLeastOneProfileBeforeTime = true;
            }
        }

        if ($atLeastOneProfileBeforeTime) {
            file_put_contents($tmpIndexFileName, stream_get_contents($handle), FILE_APPEND);
            unlink($indexFilename);
            rename($tmpIndexFileName, $indexFilename);
        } else {
            fclose($handle);
        }


        // TODO TODO make it only once
//        $dateOk = false;
//        if ($handle = fopen($indexFilename, 'c+')) {
////            if(!flock($handle,LOCK_EX)){fclose($handle);}
//            $offset = 0;
//            $len = filesize($indexFilename);
//            while (($line = fgets($handle)) !== false) {
//                if (false === $dateOk) {
//                    $csv = str_getcsv($line);
//                    $time = (int) $csv[4];
//
//                    if ($time > $removeBeforeDate) {
//                        $dateOk = true;
//                    } else {
//                        dump('REMOVE LINE: '.$line);
//                        $offset += strlen($line);
//                        continue;
//                    }
//                }
//                $pos = ftell($handle);
////                $fseek = $pos - strlen($line) - $offset;
//                $fseek = $offset;
//                dump(sprintf('La position finale vaut %s octets', $fseek));
//                dump('On met la ligne: ', $line);
//                dump(sprintf('La position finale vaut %s octets', $pos));
//
//                fseek($handle, $fseek);
//                fputs($handle, $line);
//                fseek($handle, $pos);
//            }
//
//            fflush($handle);
//            ftruncate($handle, ($len - $offset));
////            flock($handle,LOCK_UN);
//            fclose($handle);
//        }
    }

    public function yoloRemove(string $token)
    {
        if (file_exists($filename = $this->getFilename($token))) {
            unlink($filename);
        }
    }

    /**
     * Gets filename to store data, associated to the token.
     */
    protected function getFilename(string $token): string
    {
        // Uses 4 last characters, because first are mostly the same.
        $folderA = substr($token, -2, 2);
        $folderB = substr($token, -4, 2);

        return $this->folder.'/'.$folderA.'/'.$folderB.'/'.$token;
    }

    /**
     * Gets the index filename.
     */
    protected function getIndexFilename(): string
    {
        return $this->folder.'/index.csv';
    }

    /**
     * Reads a line in the file, backward.
     *
     * This function automatically skips the empty lines and do not include the line return in result value.
     *
     * @param resource $file The file resource, with the pointer placed at the end of the line to read
     */
    protected function readLineFromFile($file): mixed
    {
        $line = '';
        $position = ftell($file);

        if (0 === $position) {
            return null;
        }

        while (true) {
            $chunkSize = min($position, 1024);
            $position -= $chunkSize;
            fseek($file, $position);

            if (0 === $chunkSize) {
                // bof reached
                break;
            }

            $buffer = fread($file, $chunkSize);

            if (false === ($upTo = strrpos($buffer, "\n"))) {
                $line = $buffer.$line;
                continue;
            }

            $position += $upTo;
            $line = substr($buffer, $upTo + 1).$line;
            fseek($file, max(0, $position), \SEEK_SET);

            if ('' !== $line) {
                break;
            }
        }

        return '' === $line ? null : $line;
    }

    protected function createProfileFromData(string $token, array $data, Profile $parent = null)
    {
        $profile = new Profile($token);
        $profile->setIp($data['ip']);
        $profile->setMethod($data['method']);
        $profile->setUrl($data['url']);
        $profile->setTime($data['time']);
        $profile->setStatusCode($data['status_code']);
        $profile->setCollectors($data['data']);

        if (!$parent && $data['parent']) {
            $parent = $this->read($data['parent']);
        }

        if ($parent) {
            $profile->setParent($parent);
        }

        foreach ($data['children'] as $token) {
            if (!$token || !file_exists($file = $this->getFilename($token))) {
                continue;
            }

            if (\function_exists('gzcompress')) {
                $file = 'compress.zlib://'.$file;
            }

            if (!$childData = unserialize(file_get_contents($file))) {
                continue;
            }

            $profile->addChild($this->createProfileFromData($token, $childData, $profile));
        }

        return $profile;
    }
}
