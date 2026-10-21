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

function listZipEntries() {
	const normalize = ( entry ) => entry.replace( /\\/g, '/' );

	if ( process.platform === 'win32' ) {
		const psZipFile = ZIP_FILE.replace( /'/g, "''" );
		const command = [
			'Add-Type -AssemblyName System.IO.Compression.FileSystem;',
			`$z = [System.IO.Compression.ZipFile]::OpenRead('${ psZipFile }');`,
			'$z.Entries.FullName;',
			'$z.Dispose()',
		].join( ' ' );
		const output = execFileSync(
			'powershell.exe',
			[ '-NoProfile', '-Command', command ],
			{ encoding: 'utf8' }
		);
		return output.split( /\r?\n/ ).filter( Boolean ).map( normalize );
	}
	const output = execFileSync( 'unzip', [ '-Z1', ZIP_FILE ], { encoding: 'utf8' } );
	return output.split( /\r?\n/ ).filter( Boolean ).map( normalize );
}

function verifyZip() {
	const entries = listZipEntries();
	const required = [ `${ SLUG }/woo-kustom-export.php` ];

	for ( const entry of required ) {
		if ( ! entries.includes( entry ) ) {
			console.error( `Fel: '${ entry }' saknas i zip-filen. Zip-struktur:`, entries );
			process.exit( 1 );
		}
	}

	for ( const entry of entries ) {
		if ( entry.startsWith( `${ SLUG }/${ SLUG }/` ) ) {
			console.error( `Fel: Dubbel mappnivå upptäckt i zip-filen: '${ entry }'.`, entries );
			process.exit( 1 );
		}
	}

	console.log( 'Zip-innehåll:' );
	for ( const entry of entries ) {
		console.log( `  ${ entry }` );
	}
}

function createZip() {
	if ( process.platform === 'win32' ) {
		execFileSync(
			'powershell.exe',
			[
				'-NoProfile',
				'-Command',
				`Compress-Archive -Path '${ SLUG }' -DestinationPath '${ ZIP_FILE }' -Force`,
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
	verifyZip();
	fs.rmSync( STAGE_DIR, { recursive: true, force: true } );
	console.log( `Klar: ${ ZIP_FILE }` );
}

main();
