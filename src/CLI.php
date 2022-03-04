<?php /** @noinspection SqlNoDataSourceInspection */

namespace splitbrain\itchy;

use splitbrain\phpcli\Options;
use splitbrain\phpcli\PSR3CLI;

/**
 * Itchy command line tool to download the game data
 */
class CLI extends PSR3CLI
{
    /** @var DataBase */
    protected $db;

    /** @var ItchAPI */
    protected $api;

    /** @inheritdoc */
    protected function setup(Options $options)
    {
        $options->registerArgument('apikey', 'Itch.io API key', true);

        $options->setHelp(
            'This tool will download information about your itch.io libraray and store it in a sqlite database, '.
            'making it easier to search and filter through what\'s available'."\n\n".
            'You need an API key from https://itch.io/user/settings/api-keys'
        );
    }

    /** @inheritdoc */
    protected function main(Options $options)
    {
        $apikey = ($options->getArgs())[0];
        $this->db = new DataBase(__DIR__ . '/../itchy.sqlite', $this);
        $this->api = new ItchAPI($apikey, $this);

        // fetch all the games
        $page = 1;
        while ($games = $this->api->fetchGamePage($page++)) {
            foreach ($games as $game) {
                $this->addGame($game);
            }
        }
    }

    /**
     * Adds a game to the database based on the given game data
     *
     * This downloads additonal data. Exisiting games will be skipped.
     * 
     * @param array $gamedata
     * @return void
     */
    protected function addGame($gamedata)
    {
        $gameid = $gamedata['game']['id'];
        $res = $this->db->query('SELECT gameid FROM games WHERE gameid = ?', [$gameid]);
        if ($res) {
            $this->success('Skipping ' . $gamedata['game']['title'] . ' (' . $gameid . ')');
            return;
        }
        $this->success('Fetching ' . $gamedata['game']['title'] . ' (' . $gameid . ')...');

        $meta = $this->api->fetchGameInfo($gamedata['game']['url']);
        $this->db->saveRecord('games',
            [
                'gameid' => $gameid,
                'key' => $gamedata['id'],
                'title' => $gamedata['game']['title'],
                'short' => isset($gamedata['game']['short_text']) ? $gamedata['game']['short_text'] : '',
                'long' => isset($meta['description']) ? $meta['description'] : '',
                'url' => $gamedata['game']['url'],
                'picurl' => isset($gamedata['game']['cover_url']) ? $gamedata['game']['cover_url'] : '',
                'bought' => $gamedata['created_at'],
                'published' => $gamedata['game']['published_at'],
                'author' => $gamedata['game']['user']['username'],
                'rating' => $meta['rating'],
                'rates' => $meta['rates'],
            ]
        );

        // do traits
        $this->db->query('DELETE FROM traits WHERE gameid = ?', [$gameid]);
        $this->saveTrait($gameid, 'is-' . $gamedata['game']['classification']);
        $this->saveTrait($gameid, $gamedata['game']['type']);
        if (isset($meta['status'])) $this->saveTrait($gameid, $meta['status']);
        if (isset($meta['genre'])) $this->saveTrait($gameid, $meta['genre']);
        if (isset($meta['tags'])) $this->saveTrait($gameid, $meta['tags']);

        // fetch filedata
        $files = $this->api->fetchDownloads($gameid, $gamedata['id']);
        foreach ($files as $file) {
            $this->db->saveRecord('files', [
                'fileid' => $file['id'],
                'gameid' => $gameid,
                'name' => $file['filename'],
                'size' => $file['size'],
                'updated' => $file['updated_at'],
            ]);

            // add extension as a trait
            $ext = strtolower((new \SplFileInfo($file['filename']))->getExtension());
            $this->saveTrait($gameid, "file-$ext");

            // add file properties as trait
            $this->saveTrait($gameid, $file['type']);
            $this->saveTrait($gameid, $file['traits']);
        }
        $this->notice('{count} file(s)', ['count' => count($files)]);

        $res = $this->db->query('SELECT trait FROM traits WHERE gameid = ?', [$gameid]);
        $this->notice(join(',', array_column($res, 'trait')));
    }

    /**
     * Save the given trait(s)
     *
     * @param int $gameid
     * @param string|string[] $trait
     * @return void
     */
    protected function saveTrait($gameid, $trait)
    {
        if (is_array($trait)) {
            foreach ($trait as $t) {
                $this->saveTrait($gameid, $t);
            }
            return;
        }

        $trait = strtolower($trait);
        $trait = str_replace('_', '-', $trait);
        $trait = str_replace(' ', '-', $trait);

        if ($trait === '') return;
        if ($trait === 'default') return;

        $this->db->saveRecord(
            'traits', [
                'gameid' => $gameid,
                'trait' => $trait
            ]
        );
    }
}
