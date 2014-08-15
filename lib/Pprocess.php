<?php
require_once(dirname(__FILE__) . "/../bin/config.php");
require_once(WWW_DIR . "/lib/rarinfo/par2info.php");
require_once(WWW_DIR . "/lib/rarinfo/archiveinfo.php");
require_once(WWW_DIR . "/lib/rarinfo/zipinfo.php");
require_once(WWW_DIR . "/lib/framework/db.php");
require_once(WWW_DIR . "/lib/category.php");
require_once(WWW_DIR . "/lib/releases.php");
require_once(WWW_DIR . "/lib/releaseimage.php");
require_once(WWW_DIR . "/lib/releaseextra.php");
require_once(WWW_DIR . "/lib/groups.php");
require_once(WWW_DIR . '/lib/nntp.php');
require_once(WWW_DIR . "/lib/site.php");
require_once(WWW_DIR . "/lib/Tmux.php");
require_once(WWW_DIR . "/lib/amazon.php");
require_once(WWW_DIR . "/lib/genres.php");
require_once(WWW_DIR . "/lib/anidb.php");
require_once(WWW_DIR . "/lib/book.php");
require_once(WWW_DIR . "/lib/Games.php");
require_once("consoletools.php");
require_once("ColorCLI.php");
require_once("nzbcontents.php");
require_once("namefixer.php");
require_once("Info.php");
require_once("prehash.php");
require_once("Sharing.php");
require_once("TraktTv.php");
require_once("Film.php");
require_once("TvAnger.php");
require_once("Konsole.php");
require_once("functions.php");
require_once("ProcessAdditional.php");

/**
 * Class PProcess
 */
class PProcess
{
	/**
	 * @var DB
	 */
	private $db;

	/**
	 * @var Groups
	 */
	private $groups;

	/**
	 * @var Nfo
	 */
	private $Nfo;

	/**
	 * @var ReleaseFiles
	 */
	private $releaseFiles;

	/**
	 * Object containing site settings.
	 *
	 * @var bool|stdClass
	 */
	private $site;

	/**
	 * Add par2 info to rar list?
	 *
	 * @var bool
	 */
	private $addpar2;

	/**
	 * Should we echo to CLI?
	 *
	 * @var bool
	 */
	private $echooutput;


	/**
	 * Instance of NameFixer.
	 *
	 * @var NameFixer
	 */
	protected $nameFixer;

	/**
	 * Constructor.
	 *
	 * @param bool $echoOutput Echo to CLI or not?
	 */
	public function __construct($echoOutput = false)
	{
		//\\ Various.
		$this->echooutput = $echoOutput;
		//\\

		//\\ Class instances.
		$s = new Sites();
		$t = new Tmux();
		$this->db = new DB();
		$this->groups = new Groups();
		$this->_par2Info = new Par2Info();
		$this->nameFixer = new NameFixer($this->echooutput);
		$this->Nfo = new Info($this->echooutput);
		$this->releaseFiles = new ReleaseFiles();
		$this->functions = new Functions(true);

		//\\ Site object.
		$this->tmux = $t->get();
		$this->site = $s->get();
		//\\

		//\\ Site settings.
		$this->addpar2 = ($this->tmux->addpar2 == 0) ? false : true;
		//\\
	}

	/**
	 * Go through every type of post proc.
	 *
	 * @param $nntp
	 *
	 * @return void
	 */
	public function processAll($nntp)
	{
		$this->processPrehash($nntp);
		$this->processAdditional($nntp);
		$this->processNfos('', $nntp);
		$this->processSharing($nntp);
		$this->processMovies();
		$this->processMusic();
		$this->processConsoleGames();
		$this->processGames();
		$this->processAnime();
		$this->processTv();
		$this->processBooks();
	}

	/**
	 * Lookup anidb if enabled - always run before tvrage.
	 *
	 * @return void
	 */
	public function processAnime()
	{
		if ($this->site->lookupanidb === '1') {
			$anidb = new AniDB($this->echooutput);
			$anidb->animetitlesUpdate();
			$anidb->processAnimeReleases();
		}
	}

	/**
	 * Process books using amazon.com.
	 *
	 * @return void
	 */
	public function processBooks()
	{
		if ($this->site->lookupbooks !== '0') {
			$books = new Books($this->echooutput);
			$books->processBookReleases();
		}
	}

	/**
	 * Lookup console games if enabled.
	 *
	 * @return void
	 */
	public function processConsoleGames()
	{
		if ($this->site->lookupgames !== '0') {
			$console = new Konsole($this->echooutput);
			$console->processConsoleReleases();
		}
	}

	/**
	 * Lookup games if enabled.
	 *
	 * @return void
	 */
	public function processGames()
	{
		if ($this->site->lookupgames !== 0) {
			$games = new Games($this->echooutput);
			$games->processGamesReleases();
		}
	}

	/**
	 * Lookup imdb if enabled.
	 *
	 * @param string $releaseToWork
	 *
	 * @return void
	 */
	public function processMovies($releaseToWork = '')
	{
		if ($this->site->lookupimdb === '1') {
			$movie = new Film($this->echooutput);
			$movie->processMovieReleases($releaseToWork);
		}
	}

	/**
	 * Lookup music if enabled.
	 *
	 * @return void
	 */
	public function processMusic()
	{
		if ($this->site->lookupmusic !== '0') {
			$music = new Music($this->echooutput);
			$music->processMusicReleases();
		}
	}

	/**
	 * Process nfo files.
	 *
	 * @param string $releaseToWork
	 * @param        $nntp
	 *
	 * @return void
	 */
	public function processNfos($releaseToWork = '', $nntp)
	{
		if ($this->site->lookupnfo === '1') {
			$this->Nfo->processNfoFiles($releaseToWork, $this->site->lookupimdb, $this->site->lookuptvrage, $groupID = '', $nntp);
		}
	}

	/**
	 * Fetch titles from predb sites.
	 *
	 * @param $nntp
	 *
	 * @return void
	 */
	public function processPrehash($nntp)
	{
		// 2014-05-31 : Web PreDB fetching is removed. Using IRC is now recommended.
	}

	/**
	 * Process comments.
	 *
	 * @param NNTP $nntp
	 */
	public function processSharing(&$nntp)
	{
		$sharing = new Sharing($this->db, $nntp);
		$sharing->start();
	}

	/**
	 * Process all TV related releases which will assign their series/episode/rage data.
	 *
	 * @param string $releaseToWork
	 *
	 * @return void
	 */
	public function processTv($releaseToWork = '')
	{
		if ($this->site->lookuptvrage === '1') {
			$tvRage = new TvAnger($this->echooutput);
			$tvRage->processTvReleases($releaseToWork, true);
		}
	}

	/**
	 * Check for passworded releases, RAR/ZIP contents and Sample/Media info.
	 *
	 * @note Called externally by tmux/bin/update_per_group and update/postprocess.php
	 *
	 * @param NNTP   $nntp          Class NNTP
	 * @param string $releaseToWork String containing SQL results. Optional.
	 * @param string $groupID       Group ID. Optional
	 *
	 * @return void
	 */
	public function processAdditional($nntp, $releaseToWork = '', $groupID = '')
	{
		$processAdditional = new ProcessAdditional($this->echooutput, $nntp, $this->db, $this->site, $this->tmux);
		$processAdditional->start($releaseToWork, $groupID);
	}

	/**
	 * Attempt to get a better name from a par2 file and categorize the release.
	 *
	 * @note Called from nzbcontents.php
	 *
	 * @param string $messageID MessageID from NZB file.
	 * @param int    $relID     ID of the release.
	 * @param int    $groupID   Group ID of the release.
	 * @param NNTP   $nntp      Class NNTP
	 * @param int    $show      Only show result or apply iy.
	 *
	 * @return bool
	 */
	public function parsePAR2($messageID, $relID, $groupID, $nntp, $show)
	{
		if ($messageID === '') {
			return false;
		}

		$query = $this->db->queryOneRow(
			sprintf('
				SELECT ID, groupID, categoryID, name, searchname, UNIX_TIMESTAMP(postdate) AS post_date, ID AS releaseID
				FROM releases WHERE isrenamed = 0 AND ID = %d',
				$relID
			)
		);

		if ($query === false) {
			return false;
		}

		// Only get a new name if the category is OTHER.
		$foundName = true;
		if (!in_array(
			(int)$query['categoryID'],
			array(
				Category::CAT_MOVIE_OTHER,
				Category::CAT_PC_MOBILEOTHER,
				Category::CAT_TV_OTHER,
				Category::CAT_XXX_OTHER,
				Category::CAT_MISC_OTHER
			)
		)
		) {
			$foundName = false;
		}

		// Get the PAR2 file.
		$par2 = $nntp->getMessages($this->functions->getByNameByID($groupID), $messageID);
		if ($nntp->isError($par2)) {
			return false;
		}

		// Put the PAR2 into Par2Info, check if there's an error.
		$this->_par2Info->setData($par2);
		if ($this->_par2Info->error) {
			return false;
		}

		// Get the file list from Par2Info.
		$files = $this->_par2Info->getFileList();
		if ($files !== false && count($files) > 0) {

			$filesAdded = 0;

			// Loop through the files.
			foreach ($files as $file) {

				if (!isset($file['name'])) {
					continue;
				}

				// If we found a name and added 10 files, stop.
				if ($foundName === true && $filesAdded > 10) {
					break;
				}

				if ($this->addpar2) {
					// Add to release files.
					if ($filesAdded < 11 &&
						$this->db->queryOneRow(
							sprintf('SELECT ID FROM releasefiles WHERE releaseID = %d AND name = %s',
								$relID, $this->db->escapeString($file['name'])
							)
						) === false
					) {
						// Try to add the files to the DB.
						if ($this->releaseFiles->add($relID, $file['name'], $file['size'], $query['post_date'], 0)) {
							$filesAdded++;
						}
					} else {
						$filesAdded++;
					}

					// Try to get a new name.
					if ($foundName === false) {
						$query['textstring'] = $file['name'];
						if ($this->nameFixer->checkName($query, 1, 'PAR2, ', 1, $show) === true) {
							$foundName = true;
						}
					}
				}
				// Update the file count with the new file count + old file count.
				$this->db->exec(
					sprintf('
						UPDATE releases SET rarinnerfilecount = rarinnerfilecount + %d
						WHERE ID = %d',
						$filesAdded,
						$relID
					)
				);
			}
			if ($foundName === true) {
				return true;
			}
		}

		return false;
	}
}