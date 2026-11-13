<?php
namespace OSM\Tools;

//how much browsing history (tbl_filter_log) the database holds, for the admin home page.
//History is only removed by contrib/osm-archive.sh; this is where an admin sees it is due.
class FilterLogStats {
	//size on disk, space inside the table that deleted rows left for new ones, estimated
	//rows and the oldest day. Read from the table statistics and the date index, so it is
	//quick on a very large table, and kept for an hour. Returns null if it cannot be read.
	public static function get(){
		$cached = json_decode(TempDB::get('filterlogstats/summary') ?? '', true);
		if (is_array($cached)){return $cached;}

		try {
			$row = DB::selectRaw('SELECT DATA_LENGTH + INDEX_LENGTH + DATA_FREE AS bytes, DATA_FREE AS free, TABLE_ROWS AS tablerows
				FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "tbl_filter_log"')[0] ?? null;
			if (is_null($row)){return null;}
			$stats = [
				'bytes' => intval($row['bytes']),
				'free' => intval($row['free']),
				'rows' => intval($row['tablerows']),
				'oldest' => DB::selectRaw('SELECT MIN(date) AS oldest FROM tbl_filter_log')[0]['oldest'] ?? null,
			];
		} catch (\Throwable $e) {
			return null;
		}

		TempDB::set('filterlogstats/summary', json_encode($stats), 3600);
		return $stats;
	}

	public static function size($bytes){
		foreach (['GB' => 1073741824, 'MB' => 1048576] as $unit => $size){
			if ($bytes >= $size){return number_format($bytes / $size, 1).' '.$unit;}
		}
		return number_format($bytes / 1024, 0).' KB';
	}

	//one line for the admin home page, with a reminder once the history goes back more
	//than filterLogWarnDays days
	public static function html(){
		$stats = static::get();
		if (is_null($stats) || is_null($stats['oldest'])){return '';}

		//rounded: a day with a clock change is 23 or 25 hours long
		$days = intval(round((strtotime(date('Y-m-d')) - strtotime($stats['oldest'])) / 86400));
		$html = '<div class="historysize">Browsing history in the database: '
			.static::size($stats['bytes']).' on disk'
			.($stats['free'] > 0 ? ' ('.static::size($stats['free']).' of it free for new history)' : '')
			.', about '.number_format($stats['rows']).' entries, going back to '.htmlentities($stats['oldest'])
			.' ('.number_format($days).' days).';

		$warnDays = intval(Config::get('filterLogWarnDays'));
		if ($warnDays > 0 && $days > $warnDays){
			$html .= '<br /><span class="warn">It goes back more than '.number_format($warnDays).' days.</span> '
				.'To move old history out of the database into files, run <code>contrib/osm-archive.sh</code> on the server '
				.'(see <code>contrib/README.md</code>), or raise <code>filterLogWarnDays</code> in the Config Editor.';
		}
		return $html.'</div>';
	}
}
