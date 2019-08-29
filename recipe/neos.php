<?php

namespace Deployer;

require_once 'recipe/flow_framework.php';
require_once 'Packages/Libraries/deployer/recipes/recipe/slack.php';
require_once __DIR__ . '/../functions.php';

set('flow_context', 'Production/Live');

set('bash_sync', 'https://raw.githubusercontent.com/jonnitto/bash/master/bash.sh');

// Share global configuration
set('shared_files', [
    'Configuration/Settings.yaml',
]);

set('slack_title', function () {
    return get('application', getRealHostname());
});

// Set default values
set('port', 22);
set('forwardAgent', false);
set('multiplexing', true);
set('deployUser', function () {
    $gitUser = runLocally('git config --get user.name');
    return $gitUser ? $gitUser : get('user');
});
set('slack_text', '_{{deployUser}}_ deploying `{{branch}}` to *{{target}}*');


desc('Flush caches');
task('deploy:flush_caches', function () {
    $caches = get('flushCache', false);
    if (is_array($caches)) {
        foreach ($caches as $cache) {
            run('FLOW_CONTEXT={{flow_context}} {{bin/php}} {{release_path}}/{{flow_command}} cache:flushone ' . $cache);
        }
    }
});
after('deploy:symlink', 'deploy:flush_caches');


desc('Create and/or read the deployment key');
task('ssh:key', function () {
    $hasKey = test('[ -f ~/.ssh/id_rsa.pub ]');
    if (!$hasKey) {
        run('cat /dev/zero | ssh-keygen -q -N "" -t rsa -b 4096 -C "$(hostname -f)"');
    }
    $pub = run('cat ~/.ssh/id_rsa.pub');
    writebox('Your id_rsa.pub key is:');
    writeln("<info>$pub</info>");
    writeln('');

    $repository = preg_replace('/.*@([^:]*).*/', '$1', get('repository'));
    if ($repository) {
        run("ssh-keyscan $repository >> ~/.ssh/known_hosts");
    }
})->shallow();


desc('Check if Neos is already installed');
task('install:check', function () {
    $installed = test('[ -f {{deploy_path}}/shared/Configuration/Settings.yaml ]');
    if ($installed) {
        writebox('<strong>Neos seems already installed</strong><br>Please remove the whole Neos folder to start over again.', 'red');
        exit;
    }
})->shallow()->setPrivate();


desc('Install the synchronized bash script');
task('install:bash', function () {
    if (!get('bash_sync', false)) {
        return;
    }
    run('wget -qN {{bash_sync}} -O syncBashScript.sh; source syncBashScript.sh');
})->shallow();


task('install:info', function () {
    $realHostname = getRealHostname();
    writebox("✈︎ Installing <strong>$realHostname</strong> on <strong>{{hostname}}</strong>");
})->shallow()->setPrivate();


desc('Wait for the user to continue');
task('install:wait', function () {
    writebox("<strong>Add this key as a deployment key in your repository</strong><br>under → Settings → Deploy keys");
    if (!askConfirmation(' Press enter to continue ', true)) {
        writebox('Installation canceled', 'red');
        exit;
    }
    writeln('');
})->shallow()->setPrivate();


task('install:create_database', function () {
    run(sprintf('echo %s | %s', escapeshellarg(dbFlushDbSql(get('dbName'))), dbConnectCmd(get('dbUser'), get('dbPassword'))));
})->setPrivate();


task('install:output_db', function () {
    outputTable(
        'Following database credentias are set:',
        [
            'Name' => '{{dbName}}',
            'User' => '{{dbUser}}',
            'Password' => '{{dbPassword}}'
        ]
    );
})->shallow()->setPrivate();


task('install:success', function () {
    writebox('<strong>Successfully installed!</strong><br>To deploy your site in the future, simply run <strong>dep deploy</strong>', 'green');
})->shallow()->setPrivate();

desc('Build frontend files and push them to git');
task('frontend', function () {
    $config = get('frontend', []);

    if (!array_key_exists('command', $config)) {
        $config['command'] = 'yarn pipeline';
    }

    if (!array_key_exists('message', $config)) {
        $config['message'] = 'STATIC: Build frontend resources';
    }

    if (!array_key_exists('paths', $config)) {
        $config['paths'] = ['DistributionPackages/**/Resources/Public'];
    }

    if ($config['command']) {
        runLocally($config['command'], ['timeout' => null]);
    }

    if (is_array($config['paths'])) {
        $makeCommit = false;

        foreach ($config['paths'] as $path) {
            $hasFolder = runLocally("ls $path 2>/dev/null || true");
            $hasCommits = !testLocally("git add --dry-run -- $path");
            if ($hasFolder && $hasCommits) {
                runLocally("git add $path");
                $makeCommit = true;
            }
        }

        if ($makeCommit) {
            runLocally('git commit -m "' . $config['message'] . '" || echo ""');
            runLocally('git push');
        }
    }
});


desc('Create release tag on git');
task('deploy:tag', function () {
    // Set timestamps tag
    set('tag', date('Y-m-d_T_H-i-s'));
    set('day', date('d.m.Y'));
    set('time', date('H:i:s'));

    runLocally(
        'git tag -a -m "Deployment on the {{day}} at {{time}}" "{{tag}}"'
    );
    runLocally('git push origin --tags');
});


after('deploy:failed', 'deploy:unlock');

// Execute flow publish resources after a rollback (path differs, because release_path is the old one here)
task('rollback:publishresources', function () {
    run('FLOW_CONTEXT={{flow_context}} {{bin/php}} {{release_path}}/{{flow_command}} resource:publish');
})->setPrivate();
after('rollback', 'rollback:publishresources');


before('deploy', 'slack:notify');
after('success', 'slack:notify:success');
after('deploy:failed', 'slack:notify:failure');
