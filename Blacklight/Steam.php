<?php

namespace Blacklight;

use App\Models\Settings;
use App\Models\SteamApp;
use b3rs3rk\steamfront\Main;
use DivineOmega\CliProgressBar\ProgressBar;
use Illuminate\Support\Arr;

/**
 * Class Steam.
 */
class Steam
{
    private const STEAM_MATCH_PERCENTAGE = 90;

    /**
     * @var string The parsed game name from searchname
     */
    public string $searchTerm;

    /**
     * @var int The ID of the Steam Game matched
     */
    protected int $steamGameID;

    protected $lastUpdate;

    protected Main $steamFront;

    protected ColorCLI $colorCli;

    /**
     * Steam constructor.
     *
     *
     * @throws \Exception
     */
    public function __construct()
    {
        $this->steamFront = new Main(
            [
                'country_code' => 'us',
                'local_lang' => 'english',
            ]
        );

        $this->colorCli = new ColorCLI;
    }

    /**
     * Gets all Information for the game.
     *
     * @return array|false
     */
    public function getAll(int $appID): bool|array
    {
        $res = $this->steamFront->getAppDetails($appID);

        if ($res !== false) {
            return [
                'title' => $res->name,
                'description' => $res->description['short'] ?? null,
                'cover' => $res->images['header'] ?? null,
                'backdrop' => $res->images['background'] ?? null,
                'steamid' => $res->appid,
                'directurl' => Main::STEAM_STORE_ROOT.'app/'.$res->appid,
                'publisher' => $res->publishers,
                'rating' => $res->metacritic['score'] ?? null,
                'releasedate' => $res->releasedate['date'] ?? null,
                'genres' => $res->genres !== null ? implode(',', array_column($res->genres, 'description')) : '',
            ];
        }

        $this->colorCli->notice('Steam did not return game data');

        return false;
    }

    /**
     * Searches Steam Apps table for best title match -- prefers 100% match but returns highest over 90%.
     *
     * @param  string  $searchTerm  The parsed game name from the release searchname
     * @return false|int $bestMatch The Best match from the given search term
     *
     * @throws \Exception
     */
    public function search(string $searchTerm): bool|int
    {
        $bestMatch = false;

        if (empty($searchTerm)) {
            $this->colorCli->notice('Search term cannot be empty');

            return false;
        }

        $this->populateSteamAppsTable();

        $results = SteamApp::search($searchTerm)->get();

        if ($results instanceof \Traversable) {
            $bestMatchPct = 0;
            foreach ($results as $result) {
                // If we have an exact string match set best match and break out
                if ($result['name'] === $searchTerm) {
                    $bestMatch = $result['appid'];
                    break;
                }

                similar_text(strtolower($result['name']), strtolower($searchTerm), $percent);
                // If similar_text reports an exact match set best match and break out
                if ($percent === 100) {
                    $bestMatch = $result['appid'];
                    break;
                }
                if ($percent >= self::STEAM_MATCH_PERCENTAGE && $percent > $bestMatchPct) {
                    $bestMatch = $result['appid'];
                    $bestMatchPct = $percent;
                }
            }
        }
        if ($bestMatch === false) {
            $this->colorCli->notice('Steam search returned no valid results');
        }

        return $bestMatch;
    }

    /**
     * Downloads full Steam Store dump and imports data into local table.
     *
     * @throws \Exception
     */
    public function populateSteamAppsTable(): void
    {
        $bar = new ProgressBar;
        $lastUpdate = Settings::settingValue('APIs.Steam.last_update');
        $this->lastUpdate = $lastUpdate > 0 ? $lastUpdate : 0;
        if ((time() - (int) $this->lastUpdate) > 86400) {
            // Set time we updated steam_apps table
            $this->setLastUpdated();
            $fullAppArray = $this->steamFront->getFullAppList();
            $inserted = $dupe = 0;
            $this->colorCli->info('Populating steam apps table');
            $appsArray = Arr::pluck($fullAppArray, 'apps');
            $max = count($appsArray[0]);
            $bar->setMaxProgress($max);
            foreach ($appsArray as $appArray) {
                foreach ($appArray as $app) {
                    $dupeCheck = SteamApp::query()->where('appid', '=', $app['appid'])->first(['appid']);
                    if ($dupeCheck === null) {
                        SteamApp::query()->insert(['name' => $app['name'], 'appid' => $app['appid']]);
                        $inserted++;
                    } else {
                        $dupe++;
                    }
                    $bar->advance()->display();
                }
            }

            $bar->complete();

            echo PHP_EOL.'Added '.$inserted.' new steam app(s), '.$dupe.' duplicates skipped'.PHP_EOL;
        }
    }

    /**
     * Sets the database time for last full Steam update.
     */
    private function setLastUpdated(): void
    {
        Settings::query()->where(
            [
                ['section', '=', 'APIs'],
                ['subsection', '=', 'Steam'],
                ['name', '=', 'last_update'],
            ]
        )->update(
            [
                'value' => time(),
            ]
        );
    }
}
