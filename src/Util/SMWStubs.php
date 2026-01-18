<?php

/**
 * Lightweight Semantic MediaWiki stubs used when SMW is not available (e.g. during static analysis).
 * These definitions are guarded by class_exists to avoid clashing with real SMW installations.
 */

namespace SMW {
	if ( !class_exists( StoreFactory::class ) ) {
		class StoreFactory {
			public static function getStore() {
				return new class {
					public function getSemanticData( $subject ) {
						return new SemanticData();
					}
				};
			}
		}
	}

	if ( !class_exists( DIWikiPage::class ) ) {
		class DIWikiPage {
			public static function newFromTitle( $title ) {
				return new self();
			}

			public function getTitle() {
				return new class {
					public function getNamespace() {
						return 0;
					}

					public function getText() {
						return '';
					}

					public function getPrefixedText() {
						return '';
					}
				};
			}
		}
	}

	if ( !class_exists( SemanticData::class ) ) {
		class SemanticData {
			public function getPropertyValues( $property ) {
				return [];
			}

			public function getSubSemanticData() {
				return [];
			}
		}
	}

	if ( !class_exists( DIProperty::class ) ) {
		class DIProperty {
			public static function newFromUserLabel( $label ) {
				return null;
			}
		}
	}
}

namespace {
	if ( !class_exists( 'SMWDataItem' ) ) {
		class SMWDataItem {
		}
	}

	if ( !class_exists( 'SMWDIBlob' ) ) {
		class SMWDIBlob extends SMWDataItem {
			public function getString() {
				return '';
			}
		}
	}

	if ( !class_exists( 'SMWDIString' ) ) {
		class SMWDIString extends SMWDataItem {
			public function getString() {
				return '';
			}
		}
	}

	if ( !class_exists( 'SMWDIBoolean' ) ) {
		class SMWDIBoolean extends SMWDataItem {
			public function getBoolean() {
				return false;
			}
		}
	}

	if ( !class_exists( 'SMWDINumber' ) ) {
		class SMWDINumber extends SMWDataItem {
			public function getNumber() {
				return 0;
			}
		}
	}

	if ( !class_exists( 'SMWDITime' ) ) {
		class SMWDITime extends SMWDataItem {
			public function getTimestamp() {
				return new class {
					public function format( $format ) {
						return '';
					}
				};
			}
		}
	}

	if ( !defined( 'SMW_NS_PROPERTY' ) ) {
		define( 'SMW_NS_PROPERTY', 102 );
	}
}
