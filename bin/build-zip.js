#!/usr/bin/env node
/**
 * Bygger en installationsklar WordPress-plugin-zip: woo-kustom-export.zip
 * Användning: npm run plugin-zip
 *
 * Ren Node utan beroenden; fungerar på Windows, macOS och Linux.
 * Radslut spelar ingen roll eftersom Node hanterar skriptet korrekt.
 */

'use strict';

const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const SLUG = 'woo-kustom-export';
const ROOT_DIR = path.resolve( __dirname, '..' );
const STAGE_DIR = path.join( ROOT_DIR, '.build' );
const ZIP_FILE = path.join( ROOT_DIR, `${ SLUG }.zip` );

const FILES = [
	'woo-kustom-export.php',
	'includes/class-woo-kustom-export.php',
	'includes/class-woo-kustom-csv-generator.php',
	'assets/admin.css',
	'readme.txt',
];

function ensureExists() {
	for ( const file of FILES ) {
		if ( ! fs.existsSync( path.join( ROOT_DIR, file ) ) ) {
			console.error( `Fel: Saknad fil: ${ file }` );
			process.exit( 1 );
		}
	}
}

function clean() {
	fs.rmSync( STAGE_DIR, { recursive: true, force: true } );
	if ( fs.existsSync( ZIP_FILE ) ) {
		fs.rmSync( ZIP_FILE );
	}
}

function stage() {
	for ( const file of FILES ) {
		const target = path.join( STAGE_DIR, SLUG, file );
		fs.mkdirSync( path.dirname( target ), { recursive: true } );
		fs.copyFileSync( path.join( ROOT_DIR, file ), target );
	}
}

function createZip() {
	if ( process.platform === 'win32' ) {
		execFileSync(
			'powershell.exe',
			[
				'-NoProfile',
				'-Command',
				`Compress-Archive -Path '${ SLUG }' -DestinationPath '${ ZIP_FILE }'`,
			],
			{ cwd: STAGE_DIR, stdio: 'inherit' }
		);
	} else {
		execFileSync(
			'zip',
			[ '-r', '-q', ZIP_FILE, SLUG ],
			{ cwd: STAGE_DIR, stdio: 'inherit' }
		);
	}
}

function main() {
	ensureExists();
	clean();
	stage();
	createZip();
	fs.rmSync( STAGE_DIR, { recursive: true, force: true } );
	console.log( `Klar: ${ ZIP_FILE }` );
}

main();
