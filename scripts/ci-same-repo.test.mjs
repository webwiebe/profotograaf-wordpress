import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { parse } from 'yaml';

const dir = new URL( '../.github/workflows/', import.meta.url );
const SAME_REPO = "github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository";

function onPullRequests() {
	return readdirSync( dir )
		.filter( ( file ) => /\.ya?ml$/.test( file ) )
		.map( ( file ) => ( { file, workflow: parse( readFileSync( new URL( file, dir ), 'utf8' ) ) } ) )
		.filter( ( { workflow } ) => Object.keys( workflow.on ).includes( 'pull_request' ) );
}

test( 'at least one workflow runs on pull requests', () => {
	assert.ok( onPullRequests().length > 0 );
} );

test( 'a job in a pull request workflow runs for branches in this repository only', () => {
	for ( const { file, workflow } of onPullRequests() ) {
		for ( const [ name, job ] of Object.entries( workflow.jobs ) ) {
			assert.equal( job.if, SAME_REPO, `${ file }: ${ name }` );
		}
	}
} );
