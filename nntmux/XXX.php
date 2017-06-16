<?php

namespace nntmux;

use app\models\Settings;
use nntmux\db\DB;
use nntmux\processing\adult\AEBN;
use nntmux\processing\adult\ADM;
use nntmux\processing\adult\ADE;
use nntmux\processing\adult\Hotmovies;
use nntmux\processing\adult\Popporn;


/**
 * Class XXX
 */
class XXX
{
	/**
	 * @var DB
	 */
	public $pdo;

	/**
	 * What scraper class did we use -- used for template and trailer information
	 *
	 * @var string
	 */
	protected $whichclass = '';

	/**
	 * Current title being passed through various sites/api's.
	 *
	 * @var string
	 */
	protected $currentTitle = '';

	/**
	 * @var Logger
	 */
	protected $debugging;

	/**
	 * @var bool
	 */
	protected $debug;

	/**
	 * @var bool
	 */
	protected $echooutput;

	/**
	 * @var string
	 */
	protected $imgSavePath;

	/**
	 * @var ReleaseImage
	 */
	protected $releaseImage;

	protected $currentRelID;

	protected $movieqty;

	/**
	 * @var string
	 */
	protected $showPasswords;

	protected $cookie;

	/**
	 * @var array|bool|int|string
	 */
	public $catWhere;

	/**
	 * @param array $options Echo to cli / Class instances.
	 *
	 * @throws \Exception
	 */
	public function __construct(array $options = [])
	{
		$defaults = [
			'Echo'         => false,
			'ReleaseImage' => null,
			'Settings'     => null,
		];
		$options += $defaults;

		$this->pdo = ($options['Settings'] instanceof DB ? $options['Settings'] : new DB());
		$this->releaseImage = ($options['ReleaseImage'] instanceof ReleaseImage ? $options['ReleaseImage'] : new ReleaseImage($this->pdo));

		$this->movieqty = (Settings::value('..maxxxxprocessed') !== '') ? Settings::value('..maxxxxprocessed') : 100;
		$this->showPasswords = Releases::showPasswords();
		$this->debug = NN_DEBUG;
		$this->echooutput = ($options['Echo'] && NN_ECHOCLI);
		$this->imgSavePath = NN_COVERS . 'xxx' . DS;
		$this->cookie = NN_TMP . 'xxx.cookie';
		$this->catWhere = 'AND categories_id IN (' .
			Category::XXX_DVD . ', ' .
			Category::XXX_WMV . ', ' .
			Category::XXX_XVID . ', ' .
			Category::XXX_X264 . ', ' .
			Category::XXX_SD . ', ' .
			Category::XXX_CLIPHD . ', ' .
			Category::XXX_CLIPSD . ', ' .
			Category::XXX_WEBDL . ') ';

		if (NN_DEBUG || NN_LOGGING) {
			$this->debug = true;
			try {
				$this->debugging = new Logger();
			} catch (LoggerException $error) {
				$this->debug = false;
			}
		}
	}

	/**
	 * Get info for a xxx id.
	 *
	 * @param int $xxxid
	 *
	 * @return array|bool
	 */
	public function getXXXInfo($xxxid)
	{
		return $this->pdo->queryOneRow(sprintf('SELECT *, UNCOMPRESS(plot) AS plot FROM xxxinfo WHERE id = %d', $xxxid));
	}

	/**
	 * Get movies for movie-list admin page.
	 *
	 * @param int $start
	 * @param int $num
	 *
	 * @return array
	 */
	public function getRange($start, $num): array
	{
		return $this->pdo->query(
			sprintf('
				SELECT *,
				UNCOMPRESS(plot) AS plot
				FROM xxxinfo
				ORDER BY createddate DESC %s',
				($start === false ? '' : ' LIMIT ' . $num . ' OFFSET ' . $start)
			)
		);
	}

	/**
	 * Get count of movies for movie-list admin page.
	 *
	 * @return int
	 */
	public function getCount(): int
	{
		$res = $this->pdo->queryOneRow('SELECT COUNT(id) AS num FROM xxxinfo');

		return ($res === false ? 0 : $res['num']);
	}

	/**
	 * Get movie releases with covers for xxx browse page.
	 *
	 * @param       $cat
	 * @param       $start
	 * @param       $num
	 * @param       $orderBy
	 * @param       $maxAge
	 * @param array $excludedCats
	 *
	 * @return array
	 */
	public function getXXXRange($cat, $start, $num, $orderBy, $maxAge = -1, array $excludedCats = []): array
	{
		$catsrch = '';
		if (count($cat) > 0 && $cat[0] !== -1) {
			$catsrch = (new Category(['Settings' => $this->pdo]))->getCategorySearch($cat);
		}

		$order = $this->getXXXOrder($orderBy);

		$xxxmovies = $this->pdo->queryCalc(
			sprintf("
				SELECT SQL_CALC_FOUND_ROWS
					xxx.id,
					GROUP_CONCAT(r.id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_id
				FROM xxxinfo xxx
				LEFT JOIN releases r ON xxx.id = r.xxxinfo_id
				WHERE r.nzbstatus = 1
				AND xxx.title != ''
				AND r.passwordstatus %s
				%s %s %s %s
				GROUP BY xxx.id
				ORDER BY %s %s %s",
				$this->showPasswords,
				$this->getBrowseBy(),
				$catsrch,
				($maxAge > 0
					? 'AND r.postdate > NOW() - INTERVAL ' . $maxAge . 'DAY '
					: ''
				),
				(count($excludedCats) > 0 ? ' AND r.categories_id NOT IN (' . implode(',', $excludedCats) . ')' : ''),
				$order[0],
				$order[1],
				($start === false ? '' : ' LIMIT ' . $num . ' OFFSET ' . $start)
			), true, NN_CACHE_EXPIRY_MEDIUM
		);

		$xxxIDs = $releaseIDs = false;

		if (is_array($xxxmovies['result'])) {
			foreach ($xxxmovies['result'] AS $xxx => $id) {
				$xxxIDs[] = $id['id'];
				$releaseIDs[] = $id['grp_release_id'];
			}
		}

		$sql = sprintf("
			SELECT
				GROUP_CONCAT(r.id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_id,
				GROUP_CONCAT(r.rarinnerfilecount ORDER BY r.postdate DESC SEPARATOR ',') AS grp_rarinnerfilecount,
				GROUP_CONCAT(r.haspreview ORDER BY r.postdate DESC SEPARATOR ',') AS grp_haspreview,
				GROUP_CONCAT(r.passwordstatus ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_password,
				GROUP_CONCAT(r.guid ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_guid,
				GROUP_CONCAT(rn.releases_id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_nfoid,
				GROUP_CONCAT(g.name ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_grpname,
				GROUP_CONCAT(r.searchname ORDER BY r.postdate DESC SEPARATOR '#') AS grp_release_name,
				GROUP_CONCAT(r.postdate ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_postdate,
				GROUP_CONCAT(r.size ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_size,
				GROUP_CONCAT(r.totalpart ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_totalparts,
				GROUP_CONCAT(r.comments ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_comments,
				GROUP_CONCAT(r.grabs ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_grabs,
				GROUP_CONCAT(df.failed ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_failed,
				GROUP_CONCAT(cp.title, ' > ', c.title ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_catname,
			xxx.*, UNCOMPRESS(xxx.plot) AS plot,
			g.name AS group_name,
			rn.releases_id AS nfoid
			FROM releases r
			LEFT OUTER JOIN groups g ON g.id = r.groups_id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
			LEFT OUTER JOIN categories c ON c.id = r.categories_id
			LEFT OUTER JOIN categories cp ON cp.id = c.parentid
			INNER JOIN xxxinfo xxx ON xxx.id = r.xxxinfo_id
			WHERE r.nzbstatus = 1
			AND xxx.id IN (%s)
			AND xxx.title != ''
			AND r.passwordstatus %s
			%s %s %s %s
			GROUP BY xxx.id
			ORDER BY %s %s",
			(is_array($xxxIDs) ? implode(',', $xxxIDs) : -1),
			$this->showPasswords,
			$this->getBrowseBy(),
			$catsrch,
			($maxAge > 0
				? 'AND r.postdate > NOW() - INTERVAL ' . $maxAge . 'DAY '
				: ''
			),
			(count($excludedCats) > 0 ? ' AND r.categories_id NOT IN (' . implode(',', $excludedCats) . ')' : ''),
			$order[0],
			$order[1]
		);
		$return = $this->pdo->query($sql, true, NN_CACHE_EXPIRY_MEDIUM);
		if (!empty($return)) {
			$return[0]['_totalcount'] = $xxxmovies['total'] ?? 0;
		}

		return $return;
	}

	/**
	 * Get the order type the user requested on the movies page.
	 *
	 * @param $orderBy
	 *
	 * @return array
	 */
	protected function getXXXOrder($orderBy): array
	{
		$orderArr = explode('_', (($orderBy === '') ? 'MAX(r.postdate)' : $orderBy));
		switch ($orderArr[0]) {
			case 'title':
				$orderField = 'xxx.title';
				break;
			case 'posted':
			default:
				$orderField = 'MAX(r.postdate)';
				break;
		}

		return [$orderField, isset($orderArr[1]) && preg_match('/^asc|desc$/i', $orderArr[1]) ? $orderArr[1] : 'desc'];
	}

	/**
	 * Order types for xxx page.
	 *
	 * @return array
	 */
	public function getXXXOrdering(): array
	{
		return ['title_asc', 'title_desc', 'name_asc', 'name_desc', 'size_asc', 'size_desc', 'posted_asc', 'posted_desc', 'cat_asc', 'cat_desc'];
	}

	/**
	 * @return string
	 */
	protected function getBrowseBy(): string
	{
		$browseBy = ' ';
		$browseByArr = ['title', 'director', 'actors', 'genre', 'id'];
		foreach ($browseByArr as $bb) {
			if (isset($_REQUEST[$bb]) && !empty($_REQUEST[$bb])) {
				$bbv = stripslashes($_REQUEST[$bb]);
				if ($bb === 'genre') {
					$bbv = $this->getGenreID($bbv);
				}
				if ($bb === 'id') {
					$browseBy .= 'AND xxx.' . $bb . '=' . $bbv;
				} else {
					$browseBy .= 'AND xxx.' . $bb . ' ' . $this->pdo->likeString($bbv, true, true);
				}
			}
		}

		return $browseBy;
	}

	/**
	 * Create click-able links to actors/genres/directors/etc..
	 *
	 * @param $data
	 * @param $field
	 *
	 * @return string
	 */
	public function makeFieldLinks($data, $field): string
	{
		if (empty($data[$field])) {
			return '';
		}

		$tmpArr = explode(',', $data[$field]);
		$newArr = [];
		$i = 0;
		foreach ($tmpArr as $ta) {
			if (trim($ta) === '') {
				continue;
			}
			if ($field === 'genre') {
				$ta = $this->getGenres(true, $ta);
				$ta = $ta['title'];
			}
			if ($i > 7) {
				break;
			} //only use first 8
			$newArr[] = '<a href="' . WWW_TOP . '/xxx?' . $field . '=' . urlencode($ta) . '" title="' . $ta . '">' . $ta . '</a>';
			$i++;
		}

		return implode(', ', $newArr);
	}

	/**
	 * Update XXX Information from getXXXCovers.php in misc/testing/PostProc
	 *
	 * @param string $id
	 * @param string $title
	 * @param string $tagLine
	 * @param string $plot
	 * @param string $genre
	 * @param string $director
	 * @param string $actors
	 * @param string $extras
	 * @param string $productInfo
	 * @param string $trailers
	 * @param string $directUrl
	 * @param string $classUsed
	 * @param string $cover
	 * @param string $backdrop
	 */
	public function update(
		$id = '', $title = '', $tagLine = '', $plot = '', $genre = '', $director = '',
		$actors = '', $extras = '', $productInfo = '', $trailers = '', $directUrl = '', $classUsed = '', $cover = '', $backdrop = ''
	): void
	{
		if (!empty($id)) {

			$this->pdo->queryExec(
				sprintf('UPDATE xxxinfo	SET title = %s, tagline = %s, plot = COMPRESS(%s), genre = %s, director = %s,
					actors = %s, extras = %s, productinfo = %s, trailers = %s, directurl = %s,
					classused = %s, cover = %d, backdrop = %d, updateddate = NOW()
					WHERE id = %d',
					$this->pdo->escapeString($title),
					$this->pdo->escapeString($tagLine),
					$this->pdo->escapeString($plot),
					$this->pdo->escapeString(substr($genre, 0, 64)),
					$this->pdo->escapeString($director),
					$this->pdo->escapeString($actors),
					$this->pdo->escapeString($extras),
					$this->pdo->escapeString($productInfo),
					$this->pdo->escapeString($trailers),
					$this->pdo->escapeString($directUrl),
					$this->pdo->escapeString($classUsed),
					(empty($cover) ? 0 : $cover),
					(empty($backdrop) ? 0 : $backdrop),
					$id
				)
			);
		}
	}

	/**
	 * Get all genres for search-filter.tpl
	 *
	 * @param bool $activeOnly
	 *
	 * @return array|null
	 */
	public function getAllGenres($activeOnly = false)
	{
		$ret = null;

		if ($activeOnly) {
			$res = $this->pdo->query('SELECT title FROM genres WHERE disabled = 0 AND type = ' .
				Category::XXX_ROOT . ' ORDER BY title'
			);
		} else {
			$res = $this->pdo->query('SELECT title FROM genres WHERE disabled = 1 AND type = ' .
				Category::XXX_ROOT . ' ORDER BY title'
			);
		}

		foreach ($res as $arr => $value) {
			$ret[] = $value['title'];
		}

		return $ret;
	}

	/**
	 * Get Genres for activeonly and/or an ID
	 *
	 * @param bool        $activeOnly
	 * @param null|string $gid
	 *
	 * @return array|bool
	 */
	public function getGenres($activeOnly = false, $gid = null)
	{
		if ($gid !== null) {
			$gid = ' AND id = ' . $this->pdo->escapeString($gid) . ' ORDER BY title';
		} else {
			$gid = ' ORDER BY title';
		}

		if ($activeOnly) {
			return $this->pdo->queryOneRow('SELECT title FROM genres WHERE disabled = 0 AND type = ' . Category::XXX_ROOT . $gid);
		}

		return $this->pdo->queryOneRow('SELECT title FROM genres WHERE disabled = 1 AND type = ' . Category::XXX_ROOT . $gid);
	}

	/**
	 * Get Genre id's Of the title
	 *
	 * @param $arr - Array or String
	 *
	 * @return string - If array .. 1,2,3,4 if string .. 1
	 */
	protected function getGenreID($arr): string
	{
		$ret = null;

		if (!is_array($arr)) {
			$res = $this->pdo->queryOneRow('SELECT id FROM genres WHERE title = ' . $this->pdo->escapeString($arr));
			if ($res !== false) {
				return $res['id'];
			}
		}

		foreach ($arr as $key => $value) {
			$res = $this->pdo->queryOneRow('SELECT id FROM genres WHERE title = ' . $this->pdo->escapeString($value));
			if ($res !== false) {
				$ret .= ',' . $res['id'];
			} else {
				$ret .= ',' . $this->insertGenre($value);
			}
		}

		$ret = ltrim($ret, ',');

		return $ret;
	}

	/**
	 * Inserts Genre and returns last affected row (Genre ID)
	 *
	 * @param $genre
	 *
	 * @return bool
	 */
	private function insertGenre($genre): bool
	{
		$res = '';
		if ($genre !== null) {
			$res = $this->pdo->queryInsert(sprintf('INSERT INTO genres (title, type, disabled) VALUES (%s ,%d ,%d)', $this->pdo->escapeString($genre), Category::XXX_ROOT, 0));
		}

		return $res;
	}

	/**
	 * Inserts Trailer Code by Class
	 *
	 * @param $whichclass
	 * @param $res
	 *
	 * @return string
	 */
	public function insertSwf($whichclass, $res): string
	{
		$ret = '';
		if ($whichclass === 'ade') {
			if (!empty($res)) {
				$trailers = unserialize($res, 'ade');
				$ret .= "<object width='360' height='240' type='application/x-shockwave-flash' id='EmpireFlashPlayer' name='EmpireFlashPlayer' data='" . $trailers['url'] . "'>";
				$ret .= "<param name='flashvars' value= 'streamID=" . $trailers['streamid'] . "&amp;autoPlay=false&amp;BaseStreamingUrl=" . $trailers['baseurl'] . "'>";
				$ret .= "</object>";

				return $ret;
			}
		}
		if ($whichclass === 'pop') {
			if (!empty($res)) {
				$trailers = unserialize($res, 'pop');
				$ret .= "<embed id='trailer' width='480' height='360'";
				$ret .= "flashvars='" . $trailers['flashvars'] . "' allowfullscreen='true' allowscriptaccess='always' quality='high' name='trailer' style='undefined'";
				$ret .= "src='" . $trailers['baseurl'] . "' type='application/x-shockwave-flash'>";

				return $ret;
			}
		}

		return $ret;
	}

	/**
	 * @param $movie
	 *
	 * @return false|int|string
	 * @throws \Exception
	 */
	public function updateXXXInfo($movie)
	{
		$this->whichclass = 'aebn';
		$mov = new AEBN();
		$mov->cookie = $this->cookie;
		ColorCLI::doEcho(ColorCLI::info('Checking AEBN for movie info'));
		$res = $mov->processSite($movie);

		if ($res === false) {
			$this->whichclass = 'ade';
			$mov = new ADE();
			ColorCLI::doEcho(ColorCLI::info('Checking ADE for movie info'));
			$res = $mov->processSite($movie);
		}

		if ($res === false) {
			$this->whichclass = 'pop';
			$mov = new Popporn();
			$mov->cookie = $this->cookie;
			ColorCLI::doEcho(ColorCLI::info('Checking PopPorn for movie info'));
			$res = $mov->processSite($movie);
		}

		if ($res === false) {
			$this->whichclass = 'hotm';
			$mov = new Hotmovies();
			$mov->cookie = $this->cookie;
			ColorCLI::doEcho(ColorCLI::info('Checking HotMovies for movie info'));
			$res = $mov->processSite($movie);
		}

		// Last in list as it doesn't have trailers
		if ($res === false) {
			$this->whichclass = 'adm';
			$mov = new ADM();
			$mov->cookie = $this->cookie;
			ColorCLI::doEcho(ColorCLI::info('Checking ADM for movie info'));
			$res = $mov->processSite($movie);
		}


		// If a result is true getAll information.
		if ($res) {
			if ($this->echooutput) {

				switch ($this->whichclass) {
					case 'aebn':
						$fromstr = 'Adult Entertainment Broadcast Network';
						break;
					case 'ade':
						$fromstr = 'Adult DVD Empire';
						break;
					case 'pop':
						$fromstr = 'PopPorn';
						break;
					case 'adm':
						$fromstr = 'Adult DVD Marketplace';
						break;
					case 'hotm':
						$fromstr = 'HotMovies';
						break;
					default:
						$fromstr = '';
				}
				ColorCLI::doEcho(ColorCLI::primary('Fetching XXX info from: ' . $fromstr));
			}
			$res = $mov->getAll();
		} else {
			// Nothing was found, go ahead and set to -2
			return -2;
		}

		$res['cast'] = !empty($res['cast']) ? implode(',', $res['cast']) : '';
		$res['genres'] = !empty($res['genres']) ? $this->getGenreID($res['genres']) : '';

		$mov = [
			'trailers'    => !empty($res['trailers']) ? serialize($res['trailers']) : '',
			'extras'      => !empty($res['extras']) ? serialize($res['extras']) : '',
			'productinfo' => !empty($res['productinfo']) ? serialize($res['productinfo']) : '',
			'backdrop'    => !empty($res['backcover']) ? $res['backcover'] : 0,
			'cover'       => !empty($res['boxcover']) ? $res['boxcover'] : 0,
			'title'       => !empty($res['title']) ? html_entity_decode($res['title'], ENT_QUOTES, 'UTF-8') : '',
			'plot'        => !empty($res['synopsis']) ? html_entity_decode($res['synopsis'], ENT_QUOTES, 'UTF-8') : '',
			'tagline'     => !empty($res['tagline']) ? html_entity_decode($res['tagline'], ENT_QUOTES, 'UTF-8') : '',
			'genre'       => !empty($res['genres']) ? html_entity_decode($res['genres'], ENT_QUOTES, 'UTF-8') : '',
			'director'    => !empty($res['director']) ? html_entity_decode($res['director'], ENT_QUOTES, 'UTF-8') : '',
			'actors'      => !empty($res['cast']) ? html_entity_decode($res['cast'], ENT_QUOTES, 'UTF-8') : '',
			'directurl'   => !empty($res['directurl']) ? html_entity_decode($res['directurl'], ENT_QUOTES, 'UTF-8') : '',
			'classused'   => $this->whichclass
		];

		$check = $this->pdo->queryOneRow(sprintf('SELECT id FROM xxxinfo WHERE title = %s', $this->pdo->escapeString($mov['title'])));
		$xxxID = -2;
		if (isset($check['id'])) {
			$xxxID = $check['id'];
		}

		// BoxCover.
		$cover = 0;
		if (isset($mov['cover'])) {
			$cover = $this->releaseImage->saveImage($xxxID . '-cover', $mov['cover'], $this->imgSavePath);
		}

		// BackCover.
		$backdrop = 0;
		if (isset($mov['backdrop'])) {
			$backdrop = $this->releaseImage->saveImage($xxxID . '-backdrop', $mov['backdrop'], $this->imgSavePath, 1920, 1024);
		}

		// Update Current XXX Information
		if ($xxxID > 0) {
			$this->update($check['id'], $mov['title'], $mov['tagline'], $mov['plot'], $mov['genre'], $mov['director'], $mov['actors'], $mov['extras'], $mov['productinfo'], $mov['trailers'], $mov['directurl'], $mov['classused']);
			$xxxID = $check['id'];

			$this->pdo->queryExec(sprintf('UPDATE xxxinfo SET cover = %d, backdrop = %d  WHERE id = %d', $cover, $backdrop, $xxxID));

		} else {
			$xxxID = -2;
		}

		// Insert New XXX Information
		if ($check === false) {
			$xxxID = $this->pdo->queryInsert(
				sprintf('
					INSERT INTO xxxinfo
						(title, tagline, plot, genre, director, actors, extras, productinfo, trailers, directurl, classused, cover, backdrop, createddate, updateddate)
					VALUES
						(%s, %s, COMPRESS(%s), %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, NOW(), NOW())',
					$this->pdo->escapeString($mov['title']),
					$this->pdo->escapeString($mov['tagline']),
					$this->pdo->escapeString($mov['plot']),
					$this->pdo->escapeString(substr($mov['genre'], 0, 64)),
					$this->pdo->escapeString($mov['director']),
					$this->pdo->escapeString($mov['actors']),
					$this->pdo->escapeString($mov['extras']),
					$this->pdo->escapeString($mov['productinfo']),
					$this->pdo->escapeString($mov['trailers']),
					$this->pdo->escapeString($mov['directurl']),
					$this->pdo->escapeString($mov['classused']),
					$cover,
					$backdrop
				)
			);
		}

		if ($this->echooutput) {
			ColorCLI::doEcho(
				ColorCLI::headerOver(($xxxID !== false ? 'Added/updated XXX movie: ' . ColorCLI::primary($mov['title']) : 'Nothing to update for XXX movie: ' . ColorCLI::primary($mov['title'])))
			);
		}

		return $xxxID;
	}

	/**
	 * Process XXX releases where xxxinfo is 0
	 *
	 * @throws \Exception
	 */
	public function processXXXReleases(): void
	{
		$res = $this->pdo->query(sprintf('
				SELECT r.searchname, r.id
				FROM releases r
				WHERE r.nzbstatus = 1
				AND r.xxxinfo_id = 0
				%s
				LIMIT %d',
				$this->catWhere,
				$this->movieqty
			)
		);
		$movieCount = count($res);

		if ($movieCount > 0) {

			if ($this->echooutput) {
				ColorCLI::doEcho(ColorCLI::header('Processing ' . $movieCount . ' XXX releases.'));
			}

			// Loop over releases.
			foreach ($res as $arr) {

				$idcheck = -2;

				// Try to get a name.
				if ($this->parseXXXSearchName($arr['searchname']) !== false) {
					$check = $this->checkXXXInfoExists($this->currentTitle);
					if ($check === false) {
						$this->currentRelID = $arr['id'];
						if ($this->debug && $this->echooutput) {
							ColorCLI::doEcho('DB name: ' . $arr['searchname'], true);
						}
						if ($this->echooutput) {
							ColorCLI::doEcho(ColorCLI::primaryOver('Looking up: ') . ColorCLI::headerOver($this ->currentTitle), true);
						}

						ColorCLI::doEcho(ColorCLI::info('Local match not found, checking web!'), true);
						$idcheck = $this->updateXXXInfo($this->currentTitle);
					} else {
						ColorCLI::doEcho(ColorCLI::info('Local match found for XXX Movie: ' . ColorCLI::headerOver($this->currentTitle)), true);
						$idcheck = (int)$check['id'];
					}
				} else {
					ColorCLI::doEcho('.', true);
				}
				$this->pdo->queryExec(sprintf('UPDATE releases SET xxxinfo_id = %d WHERE id = %d %s', $idcheck, $arr['id'], $this->catWhere));
			}
		} elseif ($this->echooutput) {
			ColorCLI::doEcho(ColorCLI::header('No xxx releases to process.'));
		}
	}

	/**
	 * Checks xxxinfo to make sure releases exist
	 *
	 * @param $releaseName
	 *
	 * @return array|bool
	 */
	protected function checkXXXInfoExists($releaseName)
	{
		return $this->pdo->queryOneRow(sprintf('SELECT id, title FROM xxxinfo WHERE title %s', $this->pdo->likeString($releaseName,  false, true)));
	}

	/**
	 * Cleans up a searchname to make it easier to scrape.
	 *
	 * @param string $releaseName
	 *
	 * @return bool
	 */
	protected function parseXXXSearchName($releaseName): bool
	{
		$name = '';
		$followingList = '[^\w]((2160|1080|480|720)(p|i)|AC3D|Directors([^\w]CUT)?|DD5\.1|(DVD|BD|BR)(Rip)?|BluRay|divx|HDTV|iNTERNAL|LiMiTED|(Real\.)?Proper|RE(pack|Rip)|Sub\.?(fix|pack)|Unrated|WEB-DL|(x|H)[-._ ]?264|xvid|[Dd][Ii][Ss][Cc](\d+|\s*\d+|\.\d+)|XXX|BTS|DirFix|Trailer|WEBRiP|NFO|(19|20)\d\d)[^\w]';

		if (preg_match('/([^\w]{2,})?(?P<name>[\w .-]+?)' . $followingList . '/i', $releaseName, $matches)) {
			$name = $matches['name'];
		}

		// Check if we got something.
		if ($name !== '') {

			// If we still have any of the words in $followingList, remove them.
			$name = preg_replace('/' . $followingList . '/i', ' ', $name);
			// Remove periods, underscored, anything between parenthesis.
			$name = preg_replace('/\(.*?\)|[-._]/i', ' ', $name);
			// Finally remove multiple spaces and trim leading spaces.
			$name = trim(preg_replace('/\s{2,}/', ' ', $name));
			// Remove Private Movies {d} from name better matching.
			$name = trim(preg_replace('/^Private\s(Specials|Blockbusters|Blockbuster|Sports|Gold|Lesbian|Movies|Classics|Castings|Fetish|Stars|Pictures|XXX|Private|Black\sLabel|Black)\s\d+/i', '', $name));
			// Remove Foreign Words at the end of the name.
			$name = trim(preg_replace('/(brazilian|chinese|croatian|danish|deutsch|dutch|estonian|flemish|finnish|french|german|greek|hebrew|icelandic|italian|latin|nordic|norwegian|polish|portuguese|japenese|japanese|russian|serbian|slovenian|spanish|spanisch|swedish|thai|turkish)$/i', '', $name));

			// Check if the name is long enough and not just numbers and not file (d) of (d) and does not contain Episodes and any dated 00.00.00 which are site rips..
			if (strlen($name) > 5 && !preg_match('/^\d+$/', $name) && !preg_match('/( File \d+ of \d+|\d+.\d+.\d+)/', $name) && !preg_match('/(E\d+)/', $name) && !preg_match('/\d\d\.\d\d.\d\d/', $name)) {
				$this->currentTitle = $name;

				return true;
			}
		}

		return false;
	}
}