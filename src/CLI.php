<?php /** @noinspection SqlNoDataSourceInspection */

namespace splitbrain\itchy;

use splitbrain\phpcli\Colors;
use splitbrain\phpcli\Options;
use splitbrain\phpcli\PSR3CLI;
use splitbrain\phpcli\TableFormatter;

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
        $options->registerCommand('update', 'Update the database with the newest games from your library');
        $options->registerArgument('apikey', 'Itch.io API key', true, 'update');

        $options->registerCommand(
            'search',
            'Search the database. Prefix to terms with + or - to use them to include or exclude specific tags'
        );
        $options->registerOption('full', 'Show and search full description', 'f', false, 'search');
        $options->registerArgument('terms...', 'Search terms', false, 'search');

        $options->setHelp(
            'This tool will download information about your itch.io libraray and store it in a sqlite database, ' .
            'making it easier to search and filter through what\'s available' . "\n\n" .
            'You need an API key from https://itch.io/user/settings/api-keys'
        );
    }

    /** @inheritdoc */
    protected function main(Options $options)
    {
        $this->db = new DataBase(__DIR__ . '/../itchy.sqlite', $this);

        switch ($options->getCmd()) {
            case 'update';
                $apikey = ($options->getArgs())[0];
                $this->update($apikey);
                break;
            case 'search';
                $this->search($options->getArgs(), $options->getOpt('full'));
                break;
            default:
                echo $options->help();
        }

    }

    /**
     * Update command
     *
     * @param string $apikey
     * @return void
     */
    protected function update($apikey)
    {
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
     * Search command
     *
     * @param string $args
     * @param bool $full
     * @return void
     */
    protected function search($args, $full)
    {
        $wvars = [];
        $hvars = [];
        $WHERE = '';
        $HAVING = '';
        foreach ($args as $arg) {
            if ($arg[0] == '+') {
                $HAVING .= ' AND tags LIKE ?';
                $hvars[] = '%' . substr($arg, 1) . '%';
                continue;
            }
            if ($arg[0] == '-') {
                $HAVING .= ' AND tags NOT LIKE ?';
                $hvars[] = '%' . substr($arg, 1) . '%';
                continue;
            }

            $WHERE .= ' AND fulltext LIKE ?';
            $wvars[] = '%' . $arg . '%';
        }

        $long = '';
        if ($full) $long = '|| G.long';

        $sql = "
            SELECT G.*, GROUP_CONCAT(T.trait, ', ') AS tags,
                   G.title || G.short || G.author $long AS fulltext
              FROM games G LEFT JOIN traits T on G.gameid = T.gameid
             WHERE 1=1 $WHERE
          GROUP BY G.gameid
            HAVING 1=1 $HAVING
          ORDER BY G.rating*G.rates DESC, G.title 
        ";

        $this->debug($sql);
        $this->debug(join(', ', array_merge($wvars, $hvars)));

        $res = $this->db->query($sql, array_merge($wvars, $hvars));
        if ($res) {
            foreach ($res as $game) {
                $this->showGame($game, $full);
            }
            $this->success('Found {count} matching entries', ['count' => count($res)]);
        } else {
            $this->error('Found no matching entries');
        }

    }

    protected function showGame($info, $full)
    {
        $this->ptln(
            $this->colors->wrap($info['title'], Colors::C_LIGHTCYAN) .
            ' by ' .
            $this->colors->wrap($info['author'], Colors::C_WHITE) .
            ' ' .
            $this->colors->wrap($info['rating'] . '/5', Colors::C_YELLOW) .
            ' (' . $info['rates'] . ')'
        );
        $this->ptln($this->colors->wrap($info['short'], Colors::C_DARKGRAY));
        if ($full) $this->ptln($this->colors->wrap($info['long'], Colors::C_LIGHTGRAY));
        $this->ptln($this->colors->wrap($info['tags'], Colors::C_CYAN));
        $this->ptln($this->colors->wrap($info['url'], Colors::C_LIGHTBLUE));

        echo "\n";
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
            if ($ext) $this->saveTrait($gameid, "file-$ext");

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

    /**
     * Output the given line word wrapped
     * @param string $line
     * @return void
     */
    protected function ptln($line)
    {
        $td = new TableFormatter($this->colors);
        echo $td->format(['*'], [$line], ['']);
    }
}
