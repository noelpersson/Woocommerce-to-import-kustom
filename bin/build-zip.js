#!/usr/bin/env node
/**
 * Bygger en installationsklar WordPress-plugin-zip: woo-kustom-export.zip
 * Användning: npm run plugin-zip
 *
 * Skriver zip-filen direkt enligt ZIP-specifikationen (STORE-metoden, inga
 * beroenden) med snedstreck i sökvägarna, vilket ger identisk utmatning på
 * Windows, macOS och Linux och undviker WordPress "Incompatible Archive"-
 * problem med Compress-Archive.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const SLUG = 'woo-kustom-export';
const ROOT_DIR = path.resolve( __dirname, '..' );
const ZIP_FILE = path.join( ROOT_DIR, `${ SLUG }.zip` );

const FILES = [
	'woo-kustom-export.php',
	'includes/class-woo-kustom-export.php',
	'includes/class-woo-kustom-csv-generator.php',
	'assets/admin.css',
	'readme.txt',
];

/**
 * CRC32-tabell och beräkning enligt standardalgoritmen.
 */
const CRC_TABLE = ( () => {
	const table = new Uint32Array( 256 );
	for ( let i = 0; i < 256; i++ ) {
		let c = i;
		for ( let k = 0; k < 8; k++ ) {
			c = c & 1 ? 0xedb88320 ^ ( c >>> 1 ) : c >>> 1;
		}
		table[ i ] = c >>> 0;
	}
	return table;
} )();

function crc32( buffer ) {
	let crc = 0xffffffff;
	for ( let i = 0; i < buffer.length; i++ ) {
		crc = CRC_TABLE[ ( crc ^ buffer[ i ] ) & 0xff ] ^ ( crc >>> 8 );
	}
	return ( crc ^ 0xffffffff ) >>> 0;
}

/**
 * DOS-datum/tid från nuvarande klockslag.
 */
function dosDateTime() {
	const now = new Date();
	const year = Math.max( 0, now.getFullYear() - 1980 );
	const date = ( year << 9 ) | ( ( now.getMonth() + 1 ) << 5 ) | now.getDate();
	const time = ( now.getHours() << 11 ) | ( now.getMinutes() << 5 ) | ( now.getSeconds() >> 1 );
	return { date, time };
}

/**
 * Bygg zip-filen (metod STORE, ingen komprimering) med en Local File Header
 * och Central Directory-post per fil samt End of Central Directory sist.
 *
 * @param {Array<{name:string,data:Buffer}>} entries Filposter.
 * @return {Buffer} Hela zip-filen.
 */
function buildZip( entries ) {
	const { date, time } = dosDateTime();
	const localParts = [];
	const centralParts = [];
	let offset = 0;

	for ( const entry of entries ) {
		const nameBuffer = Buffer.from( entry.name, 'utf8' );
		const crc = crc32( entry.data );
		const size = entry.data.length;

		const local = Buffer.alloc( 30 );
		local.writeUInt32LE( 0x04034b50, 0 );
		local.writeUInt16LE( 20, 4 );
		local.writeUInt16LE( 0, 6 );
		local.writeUInt16LE( 0, 8 );
		local.writeUInt16LE( time, 10 );
		local.writeUInt16LE( date, 12 );
		local.writeUInt32LE( crc, 14 );
		local.writeUInt32LE( size, 18 );
		local.writeUInt32LE( size, 22 );
		local.writeUInt16LE( nameBuffer.length, 26 );
		local.writeUInt16LE( 0, 28 );
		localParts.push( local, nameBuffer, entry.data );

		const central = Buffer.alloc( 46 );
		central.writeUInt32LE( 0x02014b50, 0 );
		central.writeUInt16LE( 20, 4 );
		central.writeUInt16LE( 20, 6 );
		central.writeUInt16LE( 0, 8 );
		central.writeUInt16LE( 0, 10 );
		central.writeUInt16LE( time, 12 );
		central.writeUInt16LE( date, 14 );
		central.writeUInt32LE( crc, 16 );
		central.writeUInt32LE( size, 20 );
		central.writeUInt32LE( size, 24 );
		central.writeUInt16LE( nameBuffer.length, 28 );
		central.writeUInt32LE( 0, 30 );
		central.writeUInt32LE( 0, 38 );
		central.writeUInt32LE( offset, 42 );
		centralParts.push( central, nameBuffer );

		offset += 30 + nameBuffer.length + size;
	}

	const centralSize = centralParts.reduce(
		( total, part ) => total + part.length,
		0
	);

	const eocd = Buffer.alloc( 22 );
	eocd.writeUInt32LE( 0x06054b50, 0 );
	eocd.writeUInt16LE( 0, 4 );
	eocd.writeUInt16LE( 0, 6 );
	eocd.writeUInt16LE( entries.length, 8 );
	eocd.writeUInt16LE( entries.length, 10 );
	eocd.writeUInt32LE( centralSize, 12 );
	eocd.writeUInt32LE( offset, 16 );
	eocd.writeUInt16LE( 0, 20 );

	return Buffer.concat( [ ...localParts, ...centralParts, eocd ] );
}

function main() {
	for ( const file of FILES ) {
		if ( ! fs.existsSync( path.join( ROOT_DIR, file ) ) ) {
			console.error( `Fel: Saknad fil: ${ file }` );
			process.exit( 1 );
		}
	}

	const entries = FILES.map( ( file ) => ( {
		name: `${ SLUG }/${ file }`,
		data: fs.readFileSync( path.join( ROOT_DIR, file ) ),
	} ) );

	const zip = buildZip( entries );
	fs.writeFileSync( ZIP_FILE, zip );

	console.log( 'Zip-innehåll:' );
	for ( const entry of entries ) {
		console.log( `  ${ entry.name }` );
	}
	console.log( `Klar: ${ ZIP_FILE }` );
}

main();
