<?php

namespace splitbrain\itchy;

use EasyRequest\Client;
use pQuery;
use Psr\Log\LoggerInterface;

/**
 * Implements mechanism to fetch game info from itch.io
 *
 * Uses the undocumented v2 API and webscraping
 */
class ItchAPI
{

    protected $apikey;
    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param string $apikey
     * @param LoggerInterface $logger
     */
    public function __construct($apikey, LoggerInterface $logger)
    {
        $this->apikey = $apikey;
        $this->logger = $logger;
    }

    /**
     * Get one page of Games from the library
     *
     * @param $page
     * @return false|array Returns false if the page has no games
     */
    public function fetchGamePage($page)
    {
        $games = $this->fetch('https://api.itch.io/profile/owned-keys?page=' . $page);
        if (!isset($games['owned_keys'])) return false;
        $count = count($games['owned_keys']);
        if (!$count) return false;
        return $games['owned_keys'];
    }

    /**
     * Parse additional info about the game from the game's itch page
     *
     * @param string $url
     * @return array
     */
    public function fetchGameInfo($url)
    {
        $html = $this->fetch($url);
        $doc = pQuery::parseStr($html);

        $data = [
            'ratings' => 0.0,
            'rates' => 0,
            'description' => $doc->query('.formatted_description')->text(),
        ];

        $rows = $doc->query('.game_info_panel_widget table tr');
        foreach ($rows as $row) {
            /** @var \pQuery\DomNode $row */
            $td = $row->query('td');

            $key = strtolower(($td[0])->text());
            switch ($key) {
                case 'tags':
                    $data[$key] = array_map(
                        function ($node) {
                            return basename($node->attr('href'));
                        },
                        iterator_to_array($td[1]->query('a')->getIterator())
                    );
                    break;
                case 'rating':
                    $data[$key] = $td[1]->query('.aggregate_rating')->attr('title');
                    $data['rates'] = trim($td[1]->text(), '()');
                    break;
                case 'genre':
                case 'category':
                case 'status':
                    $data[$key] = basename($td[1]->query('a')[0]->attr('href'));
                    break;
                default:
                    $data[$key] = $td[1]->text();
            }
        }


        return $data;
    }

    /**
     * Get list of downloadable files for the given game
     *
     * @param string $gameid
     * @param string $key
     * @return array
     */
    public function fetchDownloads($gameid, $key)
    {
        $data = $this->fetch(
            sprintf(
                'https://api.itch.io/games/%d/uploads?download_key_id=%d',
                $gameid,
                $key
            )
        );
        return $data['uploads'];
    }

    /**
     * Return an URL with which the given file can be downloaded
     *
     * @param string $fileid ID of the file to download
     * @param string $key The download key
     * @todo implement
     * @return void
     */
    public function getDownloadURL($fileid, $key)
    {
        # Get UUID
        /*
        r = requests.post(f"https://api.itch.io/games/{self.game_id}/download-sessions", headers={"Authorization": token})
            j = r.json()

            # Download
            url = f"https://api.itch.io/uploads/{d['id']}/download?api_key={token}&download_key_id={self.id}&uuid={j['uuid']}"
        */
    }

    /**
     * Query the given URL authorized
     *
     * @param string $url
     * @return array|string decoded json or raw body data
     */
    protected function fetch($url)
    {
        $response = Client::request($url, 'GET', [
            'header' => [
                'Authorization' => $this->apikey,
            ]
        ])->send();

        $content = $response->getBody()->getContents();
        $data = json_decode($content, true);
        if ($data) return $data;
        return $content;
    }
}
