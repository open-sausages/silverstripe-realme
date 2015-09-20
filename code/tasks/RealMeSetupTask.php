<?php

/**
 * Class RealMeSetupTask
 *
 * This class is intended to be run by a server administrator once the module is setup and configured via environment
 * variables, and YML fragments. The following tasks are done by this build task:
 *
 * - Check to ensure that the task is being run from the cmdline (not in the browser, it's too sensitive)
 * - Check to ensure that the task hasn't already been run, and if it has, fail unless `force=1` is passed to the script
 * - Validate all required values have been added in the appropriate place, and provide appropriate errors if not
 * - Create config.php file for simpleSAMLphp to consume, and write it in the appropriate place
 * - Create authsources.php file for simpleSAMLphp to consume, and write it to the appropriate place
 * - Create saml20-idp-remote.php file for simpleSAMLphp to consume, and write it to the appropriate place
 * - Symlink the RealMeService::$simplesaml_base_url_path from the webroot to vendor/simplesamlphp/simplesamlphp/www
 * - Output metadata XML that must be submitted to Real Me in order to integrate with ITE and Production environments
 */
class RealMeSetupTask extends BuildTask {
	/**
	 * @var RealMeService
	 */
	private $service;

	public function run($request) {
		$this->service = Injector::inst()->get('RealMeService');

		// Ensure we are running on the command-line, and not running in a browser
		if(!Director::is_cli()) {
			$this->halt(_t('RealMeSetupTask.ERRNOTCLI'));
		}

		// Validate all required values exist
		$forceRun = ($request->getVar('force') == 1);
		if($this->validateInputs($request, $forceRun)) {
			$this->halt();
		}




	}

	/**
	 * Validate all inputs to this setup script. Ensures that all required values are available, where-ever they need to
	 * be loaded from (environment variables, Config API, or directly passed to this script via the cmd-line)
	 *
	 * @param SS_HTTPRequest $request The request object for this cli process
	 * @param bool $forceRun Whether or not to force the setup (therefore skip checks around existing files)
	 * @return bool true if there were errors, false if there were none
	 */
	private function validateInputs($request, $forceRun) {
		$errors = array();

		// Ensure we haven't already run before, or if we have, that force=1 is passed
		$existingFiles = array(
			sprintf('%s/config/config.php', $this->getSimpleSAMLPhpVendorBasePath()),
			sprintf('%s/config/authsources.php', $this->getSimpleSAMLPhpVendorBasePath()),
			sprintf('%s/metadata/saml20-idp-remote.php', $this->getSimpleSAMLPhpVendorBasePath())
		);

		foreach($existingFiles as $filePath) {
			if(file_exists($filePath) && !$forceRun) {
				$errors[] = _t('RealMeSetupTask.ERRALREADYRUN', '', '', array('path' => $filePath));
			}
		}

		if(is_null($this->service->getSimpleSamlBaseUrlPath())) {
			$errors[] = _t('RealMeSetupTask.ERRNOBASEDIR');
		}

		if(is_null($this->service->getCertDir())) {
			$errors[] = _t('RealMeSetupTask.ERRNOCERTDIR');
		}

		if(is_null($this->service->getLoggingDir())) {
			$errors[] = _t('RealMeSetupTask.ERRNOLOGDIR');
		}

		if(is_null($this->service->getTempDir())) {
			$errors[] = _t('RealMeSetupTask.ERRNOTEMPDIR');
		}

		if(is_null($this->service->findOrMakeSimpleSAMLPassword())) {
			$errors[] = _t('RealMeSetupTask.ERRNOADMPASS');
		}

		if(is_null($this->service->generateSimpleSAMLSalt())) {
			$errors[] = _t('RealMeSetupTask.ERRNOSALT');
		}

		foreach(array('mts', 'ite', 'prod') as $env) {
			if(is_null($this->service->getEntityIDForEnvironment($env))) {
				$errors[] = _t('RealMeSetupTask.ERRNOENTITYID', '', '', array('env' => $env));
			}

			if(is_null($this->service->getAuthnContextForEnvironment($env))) {
				$errors[] = _t('RealMeSetupTask.ERRNOAUTHNCONTEXT', '', '', array('env' => $env));
			}

			$signingCertFile = $this->service->getSigningCertPathForEnvironment($env);
			if(is_null($signingCertFile) || !file_exists($signingCertFile)) {
				$errors[] = _t(
					'RealMeSetupTask.ERRNOSIGNINGCERT',
					'',
					'',
					array(
						'env' => $env,
						'file' => $signingCertFile,
						'const' => sprintf('REALME_%s_SIGNING_CERT_FILENAME', strtoupper($env))
					)
				);
			}

			$mutualCertFile = $this->service->getMutualCertPathForEnvironment($env);
			if(is_null($mutualCertFile) || !file_exists($mutualCertFile)) {
				$errors[] = _t(
					'RealMeSetupTask.ERRNOMUTUALCERT',
					'',
					'',
					array(
						'env' => $env,
						'file' => $mutualCertFile,
						'const' => sprintf('REALME_%s_MUTUAL_CERT_FILENAME', strtoupper($env))
					)
				);
			}
		}

		// Output validation errors, if any are found
		if(sizeof($errors) > 0) {
			$errorList = PHP_EOL . ' - ' . join(PHP_EOL . ' - ', $errors);

			$this->message(_t(
				'RealMeSetupTask.ERRVALIDATION',
				'',
				'',
				array(
					'numissues' => sizeof($errors),
					'issues' => $errorList
				)
			));
		} else {
			$this->message("Validation succeeded, continuing with setup...");
		}

		return sizeof($errors) > 0;
	}

	private function getSimpleSAMLPhpVendorBasePath() {
		return sprintf('%s/vendor/simplesamlphp/simplesamlphp', BASE_PATH);
	}

	/**
	 * Immediately halt execution of the script, with a required error message.
	 *
	 * @param string $message
	 * @return void This method never returns
	 */
	private function halt($message = "") {
		$this->message($message . PHP_EOL);
		die();
	}

	/**
	 * Output a message to the console
	 * @param string $message
	 * @return void
	 */
	private function message($message) {
		echo $message . PHP_EOL;
	}
}