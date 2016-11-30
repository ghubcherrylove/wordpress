<?php

interface SGArchiveDelegate
{
	public function getCorrectCdrFilename($filename);
	public function didExtractFile($filePath);
	public function didCountFilesInsideArchive($count);
	public function didFindExtractError($error);
	public function warn($message);
	public function didExtractArchiveMeta($meta);
	public function didStartRestoreFiles();
}

class SGArchive
{
	const VERSION = 5;
	const CHUNK_SIZE = 1048576; //1mb
	private $filePath = '';
	private $mode = '';
	private $fileHandle = null;
	private $cdrFileHandle = null;
	private $cdrFilesCount = 0;
	private $cdr = array();
	private $fileOffset = null;
	private $delegate;
	private $ranges = array();

	public function __construct($filePath, $mode, $cdrSize = 0)
	{
		$this->filePath = $filePath;
		$this->mode = $mode;
		$this->fileHandle = @fopen($filePath, $mode.'b');
		$this->clear();

		if ($cdrSize) {
			$this->cdrFilesCount = $cdrSize;
		}

		if ($mode == 'a') {

			$cdrPath = $filePath.'.cdr';

			$this->cdrFileHandle = @fopen($cdrPath, $mode.'b');
		}
	}

	public function setDelegate(SGArchiveDelegate $delegate)
	{
		$this->delegate = $delegate;
	}

	public function getCdrFilesCount()
	{
		return $this->cdrFilesCount;
	}

	public function addFileFromPath($filename, $path)
	{
		$headerSize = 0;
		$len = 0;
		$zlen = 0;
		$start = 0;

		$fp = fopen($path, 'rb');
		$fileSize = realFilesize($path);

		$state = $this->delegate->getState();
		$offset = $state->getOffset();

		if (!$state->getInprogress()) {
			$headerSize = $this->addFileHeader();
		}
		else{
			$headerSize = $state->getHeaderSize();
		}

		$this->ranges = $state->getRanges();
		if (count($this->ranges)) {
			$range = end($this->ranges); //get last range of file

			$start += $range['start'] + $range['size'];
			$zlen = $start; // get file compressed size before reload
		}

		fseek($fp, $offset); // move to point before reload
		//read file in small chunks
		while ($offset < $fileSize)
		{
			$data = fread($fp, self::CHUNK_SIZE);
			if ($data === '') {
				//When fread fails to read and compress on fly
				if ($zlen == 0 && $fileSize != 0 && strlen($data) == 0) {
					$this->delegate->warn('Failed to read file: '.basename($filename));
				}
				break;
			}

			$shouldReload = $this->delegate->shouldReload(strlen($data));

			$data = gzdeflate($data);
			$zlen += strlen($data);
			$sgArchiveSize = realFilesize($this->filePath);
			$sgArchiveSize += strlen($data);

			if($sgArchiveSize > SG_ARCHIVE_MAX_SIZE_32) {
				SGBoot::checkRequirement('intSize');
			}

			$this->write($data);

			array_push($this->ranges, array(
				'start' => $start,
				'size' => strlen($data)
			));
			$offset = ftell($fp);

			$start += strlen($data);

			SGPing::update();

			if ($shouldReload) {
				$this->delegate->saveStateData(SG_STATE_ACTION_COMPRESSING_FILES, $this->ranges, $offset, $headerSize, true);

				if (backupGuardIsReloadEnabled()) {
					$this->delegate->reload();
				}
			}
		}

		if ($state->getInprogress()) {
			$headerSize = $state->getHeaderSize();
		}

		SGPing::update();

		fclose($fp);

		$this->addFileToCdr($filename, $zlen, $len, $headerSize);
	}

	public function addFile($filename, $data)
	{
		$headerSize = $this->addFileHeader();

		if ($data)
		{
			$data = gzdeflate($data);
			$this->write($data);
		}

		$zlen = strlen($data);
		$len = 0;

		$this->addFileToCdr($filename, $zlen, $len, $headerSize);
	}

	private function addFileHeader()
	{
		//save extra
		$extra = '';

		$extraLengthInBytes = 4;
		$this->write($this->packToLittleEndian(strlen($extra), $extraLengthInBytes).$extra);

		return $extraLengthInBytes+strlen($extra);
	}

	private function addFileToCdr($filename, $zlen, $len, $headerSize)
	{
		//store cdr data for later use
		$this->addToCdr($filename, $zlen, $len);

		$this->fileOffset += $headerSize + $zlen;
	}

	public function finalize()
	{
		$this->addFooter();

		fclose($this->fileHandle);

		$this->clear();
	}

	private function addFooter()
	{
		$footer = '';

		//save version
		$footer .= $this->packToLittleEndian(self::VERSION, 1);

		$tables = SGConfig::get('SG_BACKUPED_TABLES');

		if ($tables) {
			$table = json_encode($tables);
		}
		else {
			$tables = "";
		}

		//save db prefix, site and home url for later use
		$extra = json_encode(array(
			'siteUrl' => get_site_url(),
			'home' => get_home_url(),
			'dbPrefix' => SG_ENV_DB_PREFIX,
			'tables' => $tables,
			'method' => SGConfig::get('SG_BACKUP_TYPE')
		));

		//extra size
		$footer .= $this->packToLittleEndian(strlen($extra), 4).$extra;

		//save cdr size
		$footer .= $this->packToLittleEndian($this->cdrFilesCount, 4);

		$this->write($footer);

		//save cdr
		$cdrLen = $this->writeCdr();

		//save offset to the start of footer
		$len = $cdrLen+strlen($extra)+13;
		$this->write($this->packToLittleEndian($len, 4));
	}

	private function writeCdr()
	{
		@fclose($this->cdrFileHandle);

		$cdrLen = 0;
		$fp = @fopen($this->filePath.'.cdr', 'rb');

		while (!feof($fp))
		{
			$data = fread($fp, self::CHUNK_SIZE);
			$cdrLen += strlen($data);
			$this->write($data);
		}

		@fclose($fp);
		@unlink($this->filePath.'.cdr');

		return $cdrLen;
	}

	private function clear()
	{
		$this->cdr = array();
		$this->fileOffset = 0;
		$this->cdrFilesCount = 0;
	}

	private function addToCdr($filename, $compressedLength, $uncompressedLength)
	{
		$rec = $this->packToLittleEndian(0, 4); //crc (not used in this version)
		$rec .= $this->packToLittleEndian(strlen($filename), 2);
		$rec .= $filename;
		// file offset, compressed length, uncompressed length all are writen in 8 bytes to cover big integer size
		$rec .= $this->packToLittleEndian($this->fileOffset, 8);
		$rec .= $this->packToLittleEndian($compressedLength, 8);
		$rec .= $this->packToLittleEndian($uncompressedLength, 8); //uncompressed size (not used in this version)
		$rec .= $this->packToLittleEndian(count($this->ranges), 4);

		foreach ($this->ranges as $range) {
			// start and size all are writen in 8 bytes to cover big integer size
			$rec .= $this->packToLittleEndian($range['start'], 8);
			$rec .= $this->packToLittleEndian($range['size'], 8);
		}

		fwrite($this->cdrFileHandle, $rec);
		fflush($this->cdrFileHandle);

		$this->cdrFilesCount++;
	}

	private function write($data)
	{
		$result = fwrite($this->fileHandle, $data);
		if ($result === FALSE) {
			throw new SGExceptionIO('Failed to write in archive');
		}
		fflush($this->fileHandle);
	}

	private function read($length)
	{
		$result = fread($this->fileHandle, $length);
		if ($result === FALSE) {
			throw new SGExceptionIO('Failed to read from archive');
		}
		return $result;
	}

	private function packToLittleEndian($value, $size = 4)
	{
		if (is_int($value))
		{
			$size *= 2; //2 characters for each byte
			$value = str_pad(dechex($value), $size, '0', STR_PAD_LEFT);
			return strrev(pack('H'.$size, $value));
		}

		$hex = str_pad($value->toHex(), 16, '0', STR_PAD_LEFT);

		$high = substr($hex, 0, 8);
		$low  = substr($hex, 8, 8);

		$high = strrev(pack('H8', $high));
		$low = strrev(pack('H8', $low));

		return $low.$high;
	}

	public function extractTo($destinationPath)
	{
		//read offset
		fseek($this->fileHandle, -4, SEEK_END);
		$offset = hexdec($this->unpackLittleEndian($this->read(4), 4));

		//read version
		fseek($this->fileHandle, -$offset, SEEK_END);
		$version = hexdec($this->unpackLittleEndian($this->read(1), 1));
		SGConfig::set('SG_CURRENT_ARCHIVE_VERSION', $version);

		//read extra size (not used in this version)
		$extraSize = hexdec($this->unpackLittleEndian($this->read(4), 4));

		//read extra
		$extra = array();
		if ($extraSize > 0) {
			$extra = $this->read($extraSize);
			$extra = json_decode($extra, true);

			SGConfig::set('SG_OLD_SITE_URL', $extra['siteUrl']);
			SGConfig::set('SG_OLD_DB_PREFIX', $extra['dbPrefix']);
			SGConfig::set('SG_BACKUPED_TABLES', $extra['tables']);
			SGConfig::set('SG_BACKUP_TYPE', $extra['method']);
		}

		$this->delegate->didExtractArchiveMeta($extra);

		if ($version >= SG_MIN_SUPPORTED_ARCHIVE_VERSION && $version <= SG_MAX_SUPPORTED_ARCHIVE_VERSION) {
			if( !SGBoot::isFeatureAvailable('BACKUP_WITH_MIGRATION') ) {
				if ($extra['method'] != SG_BACKUP_METHOD_MIGRATE) {
					if ($extra['siteUrl'] == SG_SITE_URL) {
						if ($extra['dbPrefix'] != SG_ENV_DB_PREFIX) {
							throw new SGExceptionMigrationError("Seems you have changed database prefix. You should keep it constant to be able to restore this backup. Setup your WordPress installation with ".$extra['dbPrefix']." datbase prefix.");
						}
					}
					else {
						throw new SGExceptionMigrationError("You should install <b>BackupGuard Pro</b> to be able to migrate the website. More detailed information regarding features included in <b>Free</b> and <b>Pro</b> versions you can find here: <a href='".SG_BACKUP_SITE_URL."'>".SG_BACKUP_SITE_URL."</a>");
					}
				}
				else {
					throw new SGExceptionMigrationError("You should install <b>BackupGuard Pro</b> to be able to restore a package designed for migration.More detailed information regarding features included in <b>Free</b> and <b>Pro</b> versions you can find here: <a href='".SG_BACKUP_SITE_URL."'>".SG_BACKUP_SITE_URL."</a>");
				}
			}
		}
		else {
			throw new SGExceptionBadRequest('Invalid SGArchive file');
		}

		//read cdr size
		$cdrSize = hexdec($this->unpackLittleEndian($this->read(4), 4));

		$this->delegate->didStartRestoreFiles();
		$this->delegate->didCountFilesInsideArchive($cdrSize);

		$this->extractCdr($cdrSize, $destinationPath);
		$this->extractFiles($destinationPath);
	}

	private function extractCdr($cdrSize, $destinationPath)
	{
		while ($cdrSize)
		{
			//read crc (not used in this version)
			$this->read(4);

			//read filename
			$filenameLen = hexdec($this->unpackLittleEndian($this->read(2), 2));
			$filename = $this->read($filenameLen);
			$filename = $this->delegate->getCorrectCdrFilename($filename);

			//read file offset (not used in this version)
			$this->read(8);

			//read compressed length
			$zlen = $this->unpackLittleEndian($this->read(8), 8);
			$zlen = hexdec($zlen);

			//read uncompressed length (not used in this version)
			$this->read(8);

			$rangeLen = hexdec($this->unpackLittleEndian($this->read(4), 4));

			$ranges = array();
			for ($i=0; $i < $rangeLen; $i++) {
				$start = $this->unpackLittleEndian($this->read(8), 8);
				$start = hexdec($start);

				$size = $this->unpackLittleEndian($this->read(8), 8);
				$size = hexdec($size);

				$ranges[] = array(
					'start' => $start,
					'size' => $size
				);
			}

			$cdrSize--;

			$this->cdr[] = array($filename, $zlen, $ranges);
		}
	}

	private function extractFiles($destinationPath)
	{
		fseek($this->fileHandle, 0, SEEK_SET);

		foreach ($this->cdr as $row) {
			//read extra (not used in this version)
			$this->read(4);

			$path = $destinationPath.$row[0];
			$path = str_replace('\\', '/', $path);

			if ($path[strlen($path)-1] != '/') {//it's not an empty directory
				$path = dirname($path);
			}

			if (!$this->createPath($path)) {
				$ranges = $row[2];

				//get last range of file
				$range = end($ranges);
				$offset = $range['start'] + $range['size'];

				// skip file and continue
				fseek($this->fileHandle, $offset, SEEK_CUR);
				$this->delegate->didFindExtractError('Could not create directory: '.dirname($path));
				continue;
			}

			$path = $destinationPath.$row[0];

			$this->delegate->didStartExtractFile($path);

			if (!is_writable(dirname($path))) {
				$this->delegate->didFindExtractError('Destination path is not writable: '.dirname($path));
			}

			$fp = @fopen($path, 'wb');

			$zlen = $row[1];
			SGPing::update();
			$ranges = $row[2];

			for ($i = 0; $i<count($ranges); $i++) {
				$start = $ranges[$i]['start'];
				$size = $ranges[$i]['size'];

				$data = $this->read($size);
				$data = gzinflate($data);

				if (is_resource($fp)) {
					fwrite($fp, $data);
					fflush($fp);
				}
				SGPing::update();
			}

			if (is_resource($fp)) {
				fclose($fp);
			}

			$this->delegate->didExtractFile($path);
		}
	}

	private function unpackLittleEndian($data, $size)
	{
		$size *= 2; //2 characters for each byte

		$data = unpack('H'.$size, strrev($data));
		return $data[1];
	}

	private function createPath($path)
	{
		if (is_dir($path)) return true;
		$prev_path = substr($path, 0, strrpos($path, '/', -2) + 1);
		$return = $this->createPath($prev_path);
		if ($return && is_writable($prev_path))
		{
			if (!@mkdir($path)) return false;

			@chmod($path, 0777);
			return true;
		}

		return false;
	}
}
