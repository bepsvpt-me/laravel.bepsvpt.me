<?php

namespace App\Console\Commands;

use App\Package;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Arr;

/**
 * @template TPackage of array {
 *     total: int,
 *     next?: string,
 *     results: array{
 *         name: string,
 *         description: string,
 *         url: string,
 *         repository: string,
 *         downloads: int,
 *         favers: int
 *     }
 * }
 */
class SyncPackageList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:sync:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Laravel package list.';

    /**
     * Execute the console command.
     *
     * @return void
     *
     * @throws GuzzleException
     */
    public function handle(): void
    {
        $url = '/search.json?tags=laravel&type=library&per_page=100&page=1';

        $ids = [];

        while (true) {
            $data = $this->fetch($url);

            if (empty($data)) {
                break;
            }

            $this->save($data['results'], $ids);

            if (!isset($data['next'])) {
                break;
            }

            $url = urldecode($data['next']);
        }

        if (!empty($ids)) {
            Package::whereNotIn('id', $ids)->delete();
        }

        $this->info('Laravel package list syncs successfully.');
    }

    /**
     * Fetch remote data.
     *
     * @param  string  $url
     * @return TPackage|null
     *
     * @throws GuzzleException
     */
    protected function fetch(string $url): ?array
    {
        try {
            $response = $this->client->get($url);

            $content = $response->getBody()->getContents();

            /** @var TPackage $data */
            $data = json_decode($content, true);

            return $data;
        } catch (TransferException $e) {
            $this->fatal($e->getMessage(), ['url' => $url]);
        } catch (Exception $e) {
            $this->critical($e->getMessage(), ['url' => $url]);
        }

        return null;
    }

    /**
     * Save packages information to database.
     *
     * @param  TPackage  $packages
     * @param  int[]  $ids
     * @return void
     */
    protected function save(array $packages, array &$ids): void
    {
        $fields = ['description', 'url', 'repository', 'downloads', 'favers'];

        foreach ($packages as $package) {
            /** @var Package $model */
            $model = Package::withTrashed()->updateOrCreate(
                ['name' => $package['name']],
                Arr::only($package, $fields)
            );

            if ($model->isDirty()) {
                $this->fatal(
                    'Could not create or update package.',
                    $packages
                );
            }

            if ($model->trashed()) {
                $model->restore();
            }

            $ids[] = $model->getKey();
        }
    }
}
