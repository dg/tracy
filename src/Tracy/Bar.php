<?php

/**
 * This file is part of the Tracy (http://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 */

namespace Tracy;

use Tracy;


/**
 * Debug Bar.
 *
 * @author     David Grudl
 */
class Bar
{
	/** @var string */
	public $directory;

	/** @var string[] */
	public $info = array();

	/** @var IBarPanel[] */
	private $panels = array();

	/** @var resource */
	private $handle;

	/** @var string */
	private $id;


	public function register()
	{
		$directory = $this->directory ?: ini_get('upload_tmp_dir');

		if (isset($_GET['_tracy_debug']) && preg_match('#^\w+$#', $_GET['_tracy_debug'])) {
			$file = $directory . '/bar.' . $_GET['_tracy_debug'];
			header('Content-Type: text/javascript');
			@readfile($file);
			@unlink($file);
			exit;
		}

		$this->id = md5(uniqid(TRUE));
		$this->handle = fopen($directory . '/bar.' . $this->id, 'w');
		flock($this->handle, LOCK_EX);

		register_shutdown_function(array($this, 'render'));
	}


	/**
	 * Add custom panel.
	 * @param  IBarPanel
	 * @param  string
	 * @return self
	 */
	public function addPanel(IBarPanel $panel, $id = NULL)
	{
		if ($id === NULL) {
			$c = 0;
			do {
				$id = get_class($panel) . ($c++ ? "-$c" : '');
			} while (isset($this->panels[$id]));
		}
		$this->panels[$id] = $panel;
		return $this;
	}


	/**
	 * Returns panel with given id
	 * @param  string
	 * @return IBarPanel|NULL
	 */
	public function getPanel($id)
	{
		return isset($this->panels[$id]) ? $this->panels[$id] : NULL;
	}


	/**
	 * Renders debug bar.
	 * @return void
	 */
	public function render()
	{
		$obLevel = ob_get_level();
		$panels = array();
		foreach ($this->panels as $id => $panel) {
			$idHtml = preg_replace('#[^a-z0-9]+#i', '-', $id);
			try {
				$tab = (string) $panel->getTab();
				$panelHtml = $tab ? (string) $panel->getPanel() : NULL;
				if ($tab && $panel instanceof \Nette\Diagnostics\IBarPanel) {
					$panelHtml = preg_replace('~(["\'.\s#])nette-(debug|inner|collapsed|toggle|toggle-collapsed)(["\'\s])~', '$1tracy-$2$3', $panelHtml);
					$panelHtml = str_replace('tracy-toggle-collapsed', 'tracy-toggle tracy-collapsed', $panelHtml);
				}
				$panels[] = array('id' => $idHtml, 'tab' => $tab, 'panel' => $panelHtml);

			} catch (\Exception $e) {
				$panels[] = array(
					'id' => "error-$idHtml",
					'tab' => "Error in $id",
					'panel' => '<h1>Error: ' . $id . '</h1><div class="tracy-inner">'
						. nl2br(htmlSpecialChars($e, ENT_IGNORE)) . '</div>',
				);
				while (ob_get_level() > $obLevel) { // restore ob-level if broken
					ob_end_clean();
				}
			}
		}

		@session_start();
		$session = & $_SESSION['__NF']['debuggerbar'];
		if (preg_match('#^Location:#im', implode("\n", headers_list()))) {
			$session[] = $panels;
			return;
		}

		foreach (array_reverse((array) $session) as $reqId => $oldpanels) {
			$panels[] = array(
				'tab' => '<span title="Previous request before redirect">previous</span>',
				'panel' => NULL,
				'previous' => TRUE,
			);
			foreach ($oldpanels as $panel) {
				$panel['id'] .= '-' . $reqId;
				$panels[] = $panel;
			}
		}
		$session = NULL;
		ob_start();
		$info = array_filter($this->info);
		require __DIR__ . '/templates/bar.phtml';
		$content = ob_get_clean();
		if ($this->handle) {
			fwrite($this->handle, $content);
			fclose($this->handle);
		}

		if (!connection_aborted() && Helpers::isHtmlMode()) {
			echo "\n\n\n<!-- Tracy Debug Bar -->\n";
			echo $this->handle ? "<script src='?_tracy_debug=$this->id'></script>\n" : '<script> ' . $content . '</script>';
		}
	}

}
