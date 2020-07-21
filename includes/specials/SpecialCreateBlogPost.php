<?php
/**
 * A special page to create new blog posts (pages in the NS_BLOG namespace).
 * Based on the CreateForms extension by Aaron Wright and David Pean.
 *
 * @file
 * @ingroup Extensions
 */

use MediaWiki\MediaWikiServices;

class SpecialCreateBlogPost extends SpecialPage {

	public $tabCounter = 1;

	/**
	 * Constructor -- set up the new special page
	 */
	public function __construct() {
		parent::__construct( 'CreateBlogPost', 'createblogpost' );
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Show the special page
	 *
	 * @param mixed|null $par Parameter passed to the special page or null
	 */
	public function execute( $par ) {
		$out = $this->getOutput();
		$user = $this->getUser();
		$request = $this->getRequest();

		// If the user can't create blog posts, display an error
		if ( !$user->isAllowed( 'createblogpost' ) ) {
			throw new PermissionsError( 'createblogpost' );
		}

		// Show a message if the database is in read-only mode
		$this->checkReadOnly();

		// If user is blocked, s/he doesn't need to access this page
		if ( $user->isBlocked() ) {
			throw new UserBlockedError( $user->getBlock() );
		}

		// Set page title, robot policies, etc.
		$this->setHeaders();

		// Add CSS & JS
		$out->addModuleStyles( 'ext.blogPage.create.css' );
		$out->addModules( 'ext.blogPage.create.js' );

		// Add WikiEditor extension modules if enabled for the current user
		if ( ExtensionRegistry::getInstance()->isLoaded( 'WikiEditor' ) && $user->getOption( 'usebetatoolbar' ) ) {
			$out->addModuleStyles( 'ext.wikiEditor.styles' );
			$out->addModules( 'ext.wikiEditor' );
		}

		$services = MediaWikiServices::getInstance();

		// If the request was POSTed, we haven't submitted a request yet AND
		// we have a title, create the page...otherwise just display the
		// creation form
		if (
			$request->wasPosted() &&
			!$request->getCheck( 'wpPreview' ) &&
			$_SESSION['alreadysubmitted'] == false
		) {
			$_SESSION['alreadysubmitted'] = true;

			// Protect against cross-site request forgery (CSRF)
			if ( !$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
				$out->addWikiMsg( 'sessionfailure' );
				return;
			}

			// Create a Title object, or try to, anyway
			$userSuppliedTitle = $request->getVal( 'title2' );
			$title = Title::makeTitleSafe( NS_BLOG, $this->getUser()->getName() . '/' .  $userSuppliedTitle );

			// @todo CHECKME: are these still needed? The JS performs these
			// checks already but then again JS is also easy to fool...

			// The user didn't supply a title? Ask them to supply one.
			if ( !$userSuppliedTitle ) {
				$out->setPageTitle( $this->msg( 'errorpagetitle' ) );
				$out->addWikiMsg( 'blog-create-error-need-title' );
				$out->addReturnTo( $this->getPageTitle() );
				return;
			}

			// The user didn't supply the blog post text? Ask them to supply it.
			if ( !$request->getVal( 'wpTextbox1' ) ) {
				$out->setPageTitle( $this->msg( 'errorpagetitle' ) );
				$out->addWikiMsg( 'blog-create-error-need-content' );
				$out->addReturnTo( $this->getPageTitle() );
				return;
			}

			// Localized variables that will be used when creating the page
			$contLang = $services->getContentLanguage();
			$localizedCatNS = $contLang->getNsText( NS_CATEGORY );
			$today = $contLang->date( wfTimestampNow() );

			// Create the blog page if it doesn't already exist
			$page = WikiPage::factory( $title );
			if ( $page->exists() ) {
				$out->setPageTitle( $this->msg( 'errorpagetitle' ) );
				$out->addWikiMsg( 'blog-create-error-page-exists' );
				$out->addReturnTo( $this->getPageTitle() );
				return;
			} else {
				// The blog post will be by default categorized into two
				// categories, "Articles by User $1" and "(today's date)",
				// but the user may supply some categories themselves, so
				// we need to take those into account, too.
				$categories = [
					'[[' . $localizedCatNS . ':' .
						$this->msg(
							'blog-by-user-category',
							$this->getUser()->getName()
						)->inContentLanguage()->text() .
					']]' . "\n" .
					"[[{$localizedCatNS}:{$today}]]"
				];

				$userSuppliedCategories = $request->getVal( 'pageCtg' );
				if ( !empty( $userSuppliedCategories ) ) {
					// Explode along commas so that we will have an array that
					// we can loop over
					$userSuppliedCategories = explode( ',', $userSuppliedCategories );
					foreach ( $userSuppliedCategories as $cat ) {
						$cat = trim( $cat ); // GTFO@excess whitespace
						if ( !empty( $cat ) ) {
							$categories[] = "[[{$localizedCatNS}:{$cat}]]";
						}
					}
				}

				// Convert the array into a string
				$wikitextCategories = implode( "\n", $categories );

				// Perform the edit
				$pageContent = ContentHandler::makeContent(
					// Instead of <vote />, Wikia had Template:Blog Top over
					// here and Template:Blog Bottom at the bottom, where we
					// have the comments tag right now
					'<!--start text-->' . "\n" .
						$request->getVal( 'wpTextbox1' ) . "\n\n" .
						$wikitextCategories .
						"\n__NOEDITSECTION__",
					$page->getTitle()
				);
				$page->doEditContent(
					$pageContent,
					$this->msg( 'blog-create-summary' )->inContentLanguage()->text()
				);


				// Redirect the user to the new blog post they just created
				$out->redirect( $title->getFullURL() );
			}
		} elseif (
			$request->wasPosted() &&
			$request->getCheck( 'wpPreview' )
		) {
			// Previewing a blog post
			$out->setPageTitle( $this->msg( 'preview' ) );
			$out->addHTML(
				'<div class="previewnote"><p>' .
				Html::warningBox( $this->msg( 'previewnote' )->text() ) .
				'</p></div>'
			);
			if ( $user->isAnon() ) {
				$out->wrapWikiMsg(
					"<div id=\"mw-anon-preview-warning\" class=\"warningbox\">\n$1</div>",
					'anonpreviewwarning'
				);
			}

			// Modeled after CreateAPage's CreatePageCreateplateForm#showPreview
			$userSuppliedTitle = $request->getVal( 'title2' );
			$title = Title::makeTitleSafe( NS_BLOG, $this->getUser()->getName() . $userSuppliedTitle );

			if ( is_object( $title ) ) {
				$parser = $services->getParser();
				$parserOptions = ParserOptions::newFromUser( $user );
				$preparsed = $parser->preSaveTransform(
					$request->getVal( 'wpTextbox1' ), // We're intentionally ignoring categories (etc.) here
					$title,
					$user,
					$parserOptions,
					true
				);
				// $parserOutput = $parser->parse( $preparsed, $title, $parserOptions );

				$previewableText = $out->parseAsContent( $preparsed ); // $parserOutput->getText( [ 'enableSectionEditLinks' => false ] );

				$out->addHTML( $previewableText );
			}

			$out->addHTML( $this->getEditFormWithRules() );
		} else {
			$_SESSION['alreadysubmitted'] = false;

			$out->addHTML( $this->getEditFormWithRules() );
		}
	}

	/**
	 * Show the input field where the user can enter the blog post title.
	 * @return string HTML
	 */
	public function displayFormPageTitle() {
		$output = '<span class="create-title">' . $this->msg( 'blog-create-title' )->escaped() .
			'</span><br />';
		$output .= Html::input( 'title2', $this->getRequest()->getVal( 'title2' ), 'text', [
			'tabindex' => $this->tabCounter,
			'id' => 'title',
			'class' => 'createbox'
		] );
		$output .= '<br /><br />';
		$this->tabCounter++;
		return $output;
	}

	/**
	 * Show the input field where the user can enter the blog post body.
	 * @return string HTML
	 */
	public function displayFormPageText() {
		$output = '<span class="create-title">' . $this->msg( 'blog-create-text' )->escaped() .
			'</span><br />';
		$output .= Html::element( 'textarea', [
			'class' => 'createbox',
			'tabindex' => $this->tabCounter,
			'accesskey' => ',',
			'name' => 'wpTextbox1',
			'id' => 'wpTextbox1',
			'rows' => 10,
			'cols' => 80
		], $this->getRequest()->getVal( 'wpTextbox1' ) );
		$output .= '<br /><br />';
		$this->tabCounter++;
		return $output;
	}

	/**
	 * Show the category cloud.
	 * @return string HTML
	 */
	public function displayFormPageCategories() {
		$cloud = new BlogTagCloud( 1000 );

		$tagcloud = '<div id="create-tagcloud">';
		$tagnumber = 0;
		foreach ( $cloud->tags as $tag => $att ) {
			$tag = trim( $tag );
			$blogUserCat = str_replace( '$1', '', $this->msg( 'blog-by-user-category' )->inContentLanguage()->text() );
			// Ignore "Articles by User X" categories
			if ( !preg_match( '/' . preg_quote( $blogUserCat, '/' ) . '/', $tag ) ) {
				$slashedTag = $tag; // define variable
				// Fix for categories that contain an apostrophe
				if ( strpos( $tag, "'" ) ) {
					$slashedTag = str_replace( "'", "\'", $tag );
				}
				$tagcloud .= " <span id=\"tag-{$tagnumber}\" style=\"font-size:{$cloud->tags[$tag]['size']}{$cloud->tags_size_type}\">
					<a class=\"tag-cloud-entry\" data-blog-slashed-tag=\"" . $slashedTag . "\" data-blog-tag-number=\"{$tagnumber}\">{$tag}</a>
				</span>";
				$tagnumber++;
			}
		}
		$tagcloud .= '</div>';

		$output = '<div class="create-title">' .
			$this->msg( 'blog-create-categories' )->escaped() .
			'</div>
			<div class="categorytext">' .
				$this->msg( 'blog-create-category-help' )->escaped() .
			'</div>' . "\n";
		$output .= $tagcloud . "\n";
		$output .= '<textarea class="createbox" tabindex="' . $this->tabCounter .
			'" accesskey="," name="pageCtg" id="pageCtg" rows="2" cols="80"></textarea><br /><br />';
		$this->tabCounter++;

		return $output;
	}

	/**
	 * Display the standard copyright notice that is shown on normal edit page,
	 * on the upload form etc.
	 *
	 * @return string HTML
	 */
	public function displayCopyrightWarning() {
		global $wgRightsText;
		if ( $wgRightsText ) {
			$copywarnMsg = 'copyrightwarning';
			$copywarnMsgParams = [
				'[[' . $this->msg( 'copyrightpage' )->inContentLanguage()->text() . ']]',
				$wgRightsText
			];
		} else {
			$copywarnMsg = 'copyrightwarning2';
			$copywarnMsgParams = [
				'[[' . $this->msg( 'copyrightpage' )->inContentLanguage()->text() . ']]'
			];
		}
		return '<div class="copyright-warning">' .
			$this->msg( $copywarnMsg, $copywarnMsgParams )->parseAsBlock() .
			'</div>';
	}

	/**
	 * Show the form for creating new blog posts.
	 * @return string HTML
	 */
	public function displayForm() {
		$user = $this->getUser();
		$output = '<form id="editform" name="editform" method="post" action="' .
			htmlspecialchars( $this->getPageTitle()->getFullURL() ) . '" enctype="multipart/form-data">';
		$output .= "\n" . $this->displayFormPageTitle() . "\n";
		$output .= "\n" . $this->displayFormPageText() . "\n";

		$output .= "\n" . $this->displayFormPageCategories() . "\n";
		$output .= "\n" . $this->displayCopyrightWarning() . "\n";
		$output .= '<div class="blog-create-buttons">';
		$output .= Html::input(
			'wpSave',
			$this->msg( 'blog-create-button' )->text(),
			'submit',
			[
				'accesskey' => Linker::accesskey( 'save' ),
				'title' => $this->msg( 'tooltip-save' )->text(),
				'class' => 'createsubmit site-button'
			]
		);
		$output .= Html::input(
			'wpPreview',
			$this->msg( 'showpreview' )->text(),
			'submit',
			[
				'accesskey' => Linker::accesskey( 'preview' ),
				'title' => $this->msg( 'tooltip-preview' )->text(),
				'class' => 'site-button'
			]
		);
		$output .= '</div>';
		$output .= '<input type="hidden" value="" name="wpSection" />
			<input type="hidden" value="' . htmlspecialchars( $user->getEditToken() ) .
				'" name="wpEditToken" />';
		$output .= "\n" . '</form>' . "\n";

		return $output;
	}

	/**
	 * Get the blog editor form with the blog creation rules displayed before the
	 * editor, provided the rule message has some content, obviously.
	 *
	 * @return string HTML
	 */
	public function getEditFormWithRules() {
		$output = '';

		// Show the blog rules, if the message containing them ain't empty
		$message = $this->msg( 'blog-create-rules' );
		if ( !$message->isDisabled() ) {
			$output .= $message->escaped() . '<br />';
		}

		// Main form
		$output .= $this->displayForm();

		return $output;
	}

}
