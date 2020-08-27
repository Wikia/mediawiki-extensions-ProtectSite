<?php
/**
 * Class that handles the actual Special:ProtectSite page
 * This is a modified version of the ancient HTMLForm class.
 */
class ProtectSiteForm {
	public $mRequest, $action, $persist_data;

	/* Constructor */
	function __construct( $request, User $user ) {
		global $wgMemc;

		$titleObj = SpecialPage::getTitleFor( 'ProtectSite' );
		$this->action = htmlspecialchars( $titleObj->getLocalURL(), ENT_QUOTES );
		$this->mRequest = $request;
		$this->persist_data = new SqlBagOStuff( [] );

		/* Get data into the value variable/array */
		$prot = $wgMemc->get( $wgMemc->makeKey( 'protectsite' ) );
		if ( !$prot ) {
			$prot = $this->persist_data->get( 'protectsite' );
		}

		/* If this was a GET request */
		if ( !$this->mRequest->wasPosted() ) {
			/* If $value is an array, protection is set, allow unsetting */
			if ( is_array( $prot ) ) {
				$this->unProtectSiteForm( $prot );
			} else {
				/* If $value is not an array, protection is not set */
				$this->setProtectSiteForm();
			}
		} else {
			/* If this was a POST request, process the data sent */
			if ( $this->mRequest->getVal( 'protect' ) ) {
				$this->setProtectSite( $user );
			} else {
				$this->unProtectSite( $user );
			}
		}
	}

	function setProtectSite( User $user ) {
		global $wgOut, $wgMemc, $wgProtectSiteLimit;

		/* Get the request data */
		$request = $this->mRequest->getValues();

		/* Check to see if the time limit exceeds the limit. */
		$curr_time = time();
		if (
			( ( $until = strtotime( '+' . $request['timeout'], $curr_time ) ) === false ) ||
			( $until < $curr_time )
		) {
			$wgOut->addWikiMsg( 'protectsite-timeout-error' );
			$this->setProtectSiteForm();
		} else {
			/* Set the array values */
			$prot['createaccount'] = $request['createaccount'];
			$prot['createpage'] = $request['createpage'];
			$prot['edit'] = $request['edit'];
			$prot['move'] = $request['move'];
			$prot['upload'] = $request['upload'];
			$prot['comment'] = isset( $request['comment'] ) ? $request['comment'] : '';
			$prot['suppressLogs'] = $request['suppressLogs'];

			if ( isset( $wgProtectSiteLimit ) &&
				( $until > strtotime( '+' . $wgProtectSiteLimit, $curr_time ) )
			) {
				$request['timeout'] = $wgProtectSiteLimit;
			}

			/* Set the limits */
			$prot['until'] = strtotime( '+' . $request['timeout'], $curr_time );
			$prot['timeout'] = $request['timeout'];

			/* Write the array out to the database */
			$this->persist_data->set( 'protectsite', $prot, $prot['until'] );
			$wgMemc->set( $wgMemc->makeKey( 'protectsite' ), $prot, $prot['until'] );

			/* Create a log entry */
			$logEntry = new ManualLogEntry( 'protect', 'protect' );
			$logEntry->setPerformer( $user );
			$logEntry->setTarget( SpecialPage::getTitleFor( 'Allpages' ) );
			$logEntry->setComment(
				$prot['timeout'] . ( strlen( $prot['comment'] ) > 0 ? '; ' . $prot['comment'] : '' )
			);
			$logEntry->publish( $logEntry->insert() );

			/* Call the Unprotect Form function to display the current state. */
			$this->unProtectSiteForm( $prot );
		}
	}

	function unProtectSite( User $user ) {
		global $wgMemc;

		/* Get the request data */
		$request = $this->mRequest->getValues();

		/* Remove the data from the database to disable extension. */
		$this->persist_data->delete( 'protectsite' );
		$wgMemc->delete( $wgMemc->makeKey( 'protectsite' ) );

		if ( ! $request['suppressLogs'] ) {
			/* Create a log entry */
			$logEntry = new ManualLogEntry( 'protect', 'unprotect' );
			$logEntry->setPerformer( $user );
			$logEntry->setTarget( SpecialPage::getTitleFor( 'Allpages' ) );
			$logEntry->setComment( $request['ucomment'] );
			$logEntry->publish( $logEntry->insert() );
		}
		/* Call the Protect Form function to display the current state. */
		$this->setProtectSiteForm();
	}

	/**
	 * Override the broken function in the HTMLForm class
	 * This was fixed in r16320 of the MW source; WM bugzilla bug #7188.
	 */
	private function radiobox( $varname, $fields ) {
		// Give grep a chance to find the usages:
		// protectsite-createaccount-0, protectsite-createaccount-1, protectsite-createaccount-2,
		// protectsite-createpage-0, protectsite-createpage-1, protectsite-createpage-2,
		// protectsite-edit-0, protectsite-edit-1, protectsite-edit-2,
		// protectsite-move-0, protectsite-move-1,
		// protectsite-upload-0, protectsite-upload-1
		$options = [];

		foreach ( $fields as $value => $checked ) {
			$options['protectsite-' . $varname . '-' . $value] = $value;
		}

		return [
			'name' => $varname,
			'type' => 'radio',
			'label-message' => $varname,
			'options-messages' => $options,
		];
	}

	/**
	 * Overridden textbox method, allowing for the inclusion of something
	 * after the text box itself.
	 */
	private function textbox( $varname, $value = '', $append = '' ) {
		if ( $this->mRequest->wasPosted() ) {
			$value = $this->mRequest->getText( $varname, $value );
		}

		// Give grep a chance to find the usages:
		// protectsite-timeout, protectsite-comment, protectsite-ucomment
		$value = htmlspecialchars( $value, ENT_QUOTES );

		return [
			'type' => 'text',
			'name' => $varname,
			'label' => wfMessage( 'protectsite-' . $varname )->escaped() . $append,
			'default' => $value,
		];
	}

	/* This function outputs the field status. */
	private function showField( $name, $state ) {
		// Give grep a chance to find the usages:
		//   protectsite-createaccount, protectsite-createpage, protectsite-edit,
		//   protectsite-move, protectsite-upload
		//
		//   protectsite-createaccount-0, protectsite-createaccount-1, protectsite-createaccount-2,
		//   protectsite-createpage-0, protectsite-createpage-1, protectsite-createpage-2,
		//   protectsite-edit-0, protectsite-edit-1, protectsite-edit-2,
		//   protectsite-move-0, protectsite-move-1,
		//   protectsite-upload-0, protectsite-upload-1
		$label = Html::element(
			'b',
			[],
			wfMessage( 'protectsite-' . $name )->escaped() . ' - '
		);
		$label .= Html::element(
			'b',
			[ 'style' => 'color: ' . ( ( $state > 0 ) ? 'red' : 'green' ) ],
			wfMessage( 'protectsite-' . $name . '-' . $state )->escaped()
		);

		return [
			'type' => 'info',
			'default' => $label,
			'raw' => true,
		];
	}

	function unProtectsiteForm( $prot ) {
		global $wgOut, $wgLang;

		/* Construct page data and add to output. */
		$wgOut->addWikiMsg( 'protectsite-text-unprotect' );

		$formDescriptor = [
			$this->showField( 'createaccount', $prot['createaccount'] ),
			$this->showField( 'createpage', $prot['createpage'] ),
			$this->showField( 'edit', $prot['edit'] ),
			$this->showField( 'move', $prot['move'] ),
			$this->showField( 'upload', $prot['upload'] ),
			[
				'type' => 'info',
				'default' =>
					Html::element( 'b', [], wfMessage( 'protectsite-timeout' )->escaped() ) .
					' ' .
					Html::element(
						'i',
						[],
						$wgLang->timeAndDate( wfTimestamp( TS_MW, $prot['until'] ), true )
					),
				'raw' => true,
			],
		];

		if ( isset( $prot['comment'] ) ) {
			$formDescriptor[] = [
				'type' => 'info',
				'default' =>
					Html::element( 'b', [], wfMessage( 'protectsite-comment' )->escaped() ) .
					' ' .
					Html::element( 'i', [], $prot['comment'] ),
				'raw' => true,
			];
		}

		$formDescriptor[] = $this->textbox( 'ucomment' );
		$formDescriptor[] = [
				'name' => 'suppressLogs',
				'type' => 'check',
				'label-message' => 'protectsite-suppress-logs',
		];

		$this->createForm( 'unprotect', $formDescriptor );
	}

	function setProtectSiteForm() {
		global $wgOut, $wgProtectSiteDefaultTimeout, $wgProtectSiteLimit;

		$request = $this->mRequest->getValues();
		$createaccount = [ 0 => false, 1 => false, 2 => false ];
		$createaccount[( isset( $request['createaccount'] ) ? $request['createaccount'] : 0 )] = true;
		$createpage = [ 0 => false, 1 => false, 2 => false ];
		$createpage[( isset( $request['createpage'] ) ? $request['createpage'] : 0 )] = true;
		$edit = [ 0 => false, 1 => false, 2 => false ];
		$edit[( isset( $request['edit'] ) ? $request['edit'] : 0 )] = true;
		$move = [ 0 => false, 1 => false ];
		$move[( isset( $request['move'] ) ? $request['move'] : 0 )] = true;
		$upload = [ 0 => false, 1 => false ];
		$upload[( isset( $request['upload'] ) ? $request['upload'] : 0 )] = true;
		$suppressLogs = [ 0 => false ];
		$suppressLogs[( isset( $request['upload'] ) ? $request['upload'] : 0 )] = true;

		/* Construct page data and add to output. */
		$wgOut->addWikiMsg( 'protectsite-text-protect' );

		$formDescriptor = [
			$this->radiobox( 'createaccount', $createaccount ),
			$this->radiobox( 'createpage', $createpage ),
			$this->radiobox( 'edit', $edit ),
			$this->radiobox( 'move', $move ),
			$this->radiobox( 'upload', $upload ),
			$this->textbox(
				'timeout',
				$wgProtectSiteDefaultTimeout,
				( isset( $wgProtectSiteLimit )
					?
					' (' . wfMessage( 'protectsite-maxtimeout', $wgProtectSiteLimit )->parse() . ')'
					:
					''
				)
			),
			$this->textbox( 'comment', isset( $request['comment'] ) ? $request['comment'] : '' ),
			[
				'name' => 'suppressLogs',
				'type' => 'check',
				'label-message' => 'protectsite-suppress-logs',
			]
		];

		$this->createForm( 'protect', $formDescriptor );
	}

	private function createForm( $submitName, $formDescriptor ) {
		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor );
		$htmlForm
			->setTitle( SpecialPage::getTitleFor( 'ProtectSite' ) )
			->setMethod( 'post' )
			->setAction( $this->action )
			->setSubmitName( $submitName )
			->setSubmitTextMsg( 'protectsite-' . $submitName )
			->setWrapperLegendMsg( 'protectsite-title' )
			->prepareForm()
			->displayForm( false );
	}
}
