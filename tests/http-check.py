#!/usr/bin/env python3
"""Real HTTP checks against a disposable Sunrise WordPress installation. Stdlib only."""
import argparse
import base64
import json
import secrets
import shutil
import ssl
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--wp-root', required=True)
parser.add_argument('--url', required=True)
parser.add_argument('--insecure-local', action='store_true', help='Accept a localhost test certificate only')
args = parser.parse_args()
host = urllib.parse.urlparse(args.url).hostname or ''
if host not in ('localhost', '127.0.0.1', '::1') and not host.endswith('.localhost'):
    parser.error('Refusing to test a non-localhost URL')


def cli(code):
    result = subprocess.run(
        ['php', '-d', 'display_errors=0', shutil.which('wp'), '--path=' + args.wp_root, 'eval', code],
        capture_output=True, text=True, check=True,
    )
    return json.loads(result.stdout) if result.stdout.strip() else None


settings = cli('echo wp_json_encode(array("test"=>defined("SUNRISE_TEST_SITE") && SUNRISE_TEST_SITE,"local"=>wp_get_environment_type()==="local","cron_disabled"=>defined("DISABLE_WP_CRON") && DISABLE_WP_CRON));')
if not all(settings.values()):
    parser.error('Requires SUNRISE_TEST_SITE=true, WP_ENVIRONMENT_TYPE=local, DISABLE_WP_CRON=true')

context = ssl._create_unverified_context() if args.insecure_local else ssl.create_default_context()
login = 'sunrise-http-' + secrets.token_hex(5)
user = cli('$id=wp_insert_user(array("user_login"=>' + json.dumps(login) + ',"user_pass"=>wp_generate_password(32),"role"=>"administrator")); if(is_wp_error($id)){throw new Exception("user_create_failed");} $app=WP_Application_Passwords::create_new_application_password($id,array("name"=>"Sunrise disposable HTTP test")); echo wp_json_encode(array("id"=>$id,"password"=>$app[0],"uuid"=>$app[1]["uuid"]));')
job_ids = []
old_refresh = cli('echo wp_json_encode(get_option("sunrise_last_refresh"));')
auth = base64.b64encode((login + ':' + user['password']).encode()).decode()


def request(path, body=None, authenticate=True):
    headers = {'User-Agent': 'SunriseController/0.1.0 (+' + args.url + '/)', 'Content-Type': 'application/json'}
    if authenticate:
        headers['Authorization'] = 'Basic ' + auth
    url = args.url.rstrip('/') + '/wp-json/sunrise/v1/' + path
    req = urllib.request.Request(url, data=None if body is None else json.dumps(body).encode(), headers=headers)
    try:
        response = urllib.request.urlopen(req, context=context, timeout=40)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, dict(response.headers), json.loads(response.read())


def check(condition, label):
    if not condition:
        raise AssertionError(label)
    print('PASS: ' + label)


try:
    status, _, _ = request('inventory', authenticate=False)
    check(status == 401, 'HTTP anonymous inventory rejected')
    status, headers, data = request('inventory')
    check(status == 200 and data['api_version'] == 1, 'Application password authenticates over HTTP transport')
    check('no-store' in headers.get('Cache-Control', '') and 'Authorization' in headers.get('Vary', ''), 'Private response cache headers')
    print('Target: WordPress ' + data['core']['version'] + ', PHP ' + data['php_version'])
    cli('wp_set_password(wp_generate_password(32), ' + str(user['id']) + ');')
    check(request('inventory')[0] == 200, 'Connection survives normal password change')
    # A cooldown keeps this check from contacting update providers.
    cli('update_option("sunrise_last_refresh",time(),false);')
    task = {'request_id': str(uuid.uuid4()), 'action': 'refresh'}
    job_ids.append(task['request_id'])
    status, _, job = request('jobs', task)
    check(status == 202 and job['status'] == 'queued', 'HTTP job enqueues')
    check(request('jobs', task)[2]['id'] == job['id'], 'HTTP retry returns original job')
    status, _, done = request('jobs/' + job['id'] + '/run', {})
    check(status == 200 and done['status'] == 'completed', 'Remote runner works with traffic-driven cron disabled')
    check(request('jobs/' + job['id'] + '/run', {})[2] == done, 'HTTP repeated runner does not repeat work')
    # Exercise the actual WordPress cron entry point, not just the worker function.
    cron_task = {'request_id': str(uuid.uuid4()), 'action': 'refresh'}
    job_ids.append(cron_task['request_id'])
    check(request('jobs', cron_task)[0] == 202, 'Cron test job enqueued')
    with urllib.request.urlopen(args.url.rstrip('/') + '/wp-cron.php', context=context, timeout=40) as response:
        response.read()
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        cron_job = request('jobs/' + cron_task['request_id'])[2]
        if cron_job['status'] not in ('queued', 'running'):
            break
        time.sleep(1)
    check(cron_job['status'] == 'completed', 'Native WP-Cron executes the same queued worker')
    # Demotion revokes capabilities, without changing/revoking the credential itself.
    cli('(new WP_User(' + str(user['id']) + '))->set_role("subscriber");')
    check(request('inventory')[0] == 403, 'User demotion removes access')
    cli('(new WP_User(' + str(user['id']) + '))->set_role("administrator");')
    cli('WP_Application_Passwords::delete_application_password(' + str(user['id']) + ',' + json.dumps(user['uuid']) + ');')
    check(request('inventory')[0] == 401, 'Revocation invalidates HTTP authentication')
    replacement = cli('$a=WP_Application_Passwords::create_new_application_password(' + str(user['id']) + ',array("name"=>"Sunrise deletion check")); echo wp_json_encode($a[0]);')
    auth = base64.b64encode((login + ':' + replacement).encode()).decode()
    check(request('inventory')[0] == 200, 'Replacement credential authenticates before deletion')
    cli('require_once ABSPATH."wp-admin/includes/user.php"; wp_delete_user(' + str(user['id']) + ');')
    check(request('inventory')[0] == 401, 'Deleting the user invalidates the connection')
    print('All HTTP checks passed.')
finally:
    cli('require_once ABSPATH."wp-admin/includes/user.php"; wp_delete_user(' + str(user['id']) + ');')
    for job_id in job_ids:
        cli('$id=' + json.dumps(job_id) + '; delete_option("sunrise_job_".$id); wp_clear_scheduled_hook("sunrise_run_job",array($id)); update_option("sunrise_job_ids",array_values(array_diff(get_option("sunrise_job_ids",array()),array($id))),false);')
    cli('delete_option("sunrise_last_refresh");' if old_refresh is False else 'update_option("sunrise_last_refresh",' + json.dumps(old_refresh) + ',false);')
