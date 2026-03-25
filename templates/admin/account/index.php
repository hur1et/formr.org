<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <section class="content-header">
        <h1>User Profile </h1>
    </section>

    <section class="content">

        <div class="row">
            <div class="col-md-3">
                <?php if (!$user->isAdmin()): ?>
                    <div class="box box-warning text-center" style="background-color: #f39c12; color: #fff; padding: 25px;">
                        <div class="box-header">
                            <i class="fa fa-warning fa-2x" style="font-size: 55px; color: #fff"></i>
                        </div>
                        <div class="box-body box-profile">
                            <h3>Your account is limited. You can request for full access as specified in the documentation</h3>
                            <a href="<?= site_url('documentation/#get_started') ?>" class="btn btn-default" target="_blank"><i class="fa fa-link"></i> See Documentation</a>
                        </div>
                        <!-- /.box-body -->
                    </div>
                <?php endif; ?>

                <div class="box box-primary">
                    <div class="box-body box-profile">
                        <div class="text-center">
                            <i class="fa fa-user fa-5x"></i>
                        </div>

                        <h3 class="profile-username text-center"><?= h($names) ?></h3>

                        <p class="text-muted text-center"><?= h($affiliation) ?></p>

                        <ul class="list-group list-group-unbordered">
                            <li class="list-group-item">
                                <b>Surveys</b> <a class="pull-right" href="<?= admin_url('survey/list'); ?>"><?= $survey_count ?></a>
                            </li>
                            <li class="list-group-item">
                                <b>Runs(Studies)</b> <a class="pull-right" href="<?= admin_url('run/list'); ?>"><?= $run_count ?></a>
                            </li>
                            <li class="list-group-item">
                                <b>Email Accounts</b> <a class="pull-right" href="<?= admin_url('mail'); ?>"><?= $mail_count ?></a>
                            </li>
                        </ul>

                    </div>
                    <!-- /.box-body -->
                </div>
            </div>

            <div class="col-md-9">

                <?php Template::loadChild('public/alerts'); ?>

                <div class="nav-tabs-custom">
                    <ul class="nav nav-tabs">
                        <li class="active"><a href="#settings" data-toggle="tab" aria-expanded="true">Account Settings</a></li>
                        <li class=""><a href="#api" data-toggle="tab" aria-expanded="false">API Credentials</a></li>
                        <li class=""><a href="#data" data-toggle="tab" aria-expanded="false">Account Deletion</a></li>
                        <li class=""><a href="#2fa" data-toggle="tab" aria-expanded="false">Two Factor Authentication</a></li>
                        <?php if ($user->isAdmin()): ?>
                        <li class=""><a href="#ai" data-toggle="tab" aria-expanded="false"><i class="fa fa-robot"></i> AI Settings</a></li>
                        <?php endif; ?>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane active" id="settings">
                            <form method="post" action="">
                                <h4 class="lead"> <i class="fa fa-user"></i> Basic Information</h4>

                                <div class="form-group  col-md-6">
                                    <label class="control-label"> First Name </label>
                                    <input class="form-control" name="first_name" value="<?= h($user->first_name) ?>" autocomplete="off">
                                </div>
                                <div class="form-group  col-md-6">
                                    <label class="control-label"> Last Name </label>
                                    <input class="form-control" name="last_name" value="<?= h($user->last_name) ?>" autocomplete="off">
                                </div>
                                <div class="form-group  col-md-12">
                                    <label class="control-label"> Affiliation </label>
                                    <input class="form-control" name="affiliation" value="<?= h($user->affiliation) ?>" autocomplete="off">
                                </div>
                                <div class="clearfix"></div>

                                <h3 class="lead"> <i class="fa fa-lock"></i> Login Details (changes are effective immediately)</h3>
                                <div class="alert alert-warning col-md-7" style="font-size: 16px;">
                                    <i class="fa fa-warning"></i> &nbsp; If you do not intend to change your password, please leave the password fields empty.
                                </div>
                                <div class="clearfix"></div>

                                <div class="form-group ">
                                    <label class="control-label" for="email"><i class="fa fa-envelope-o fa-fw"></i> New Email</label>
                                    <input class="form-control" type="email" id="email" name="new_email" value="<?= h($user->email) ?>" autocomplete="new-password">
                                </div>

                                <div class="form-group ">
                                    <label class="control-label" for="pass2"><i class="fa fa-key fa-fw"></i> Enter New Password (Choose a secure phrase)</label>
                                    <input class="form-control" type="password" id="pass2" name="new_password" autocomplete="new-password">
                                </div>
                                <div class="form-group ">
                                    <label class="control-label" for="pass3"><i class="fa fa-key fa-fw"></i> Confirm New Password</label>
                                    <input class="form-control" type="password" id="pass3" name="new_password_c" autocomplete="new-password">
                                </div>
                                <p>&nbsp;</p>

                                <div class="col-md-5 no-padding confirm-changes">
                                    <label class="control-label" for="pass"><i class="fa fa-check-circle"></i> Enter Old Password to Save Changes</label>
                                    <div class="input-group input-group">
                                        <input class="form-control" type="password" id="pass" name="password" autocomplete="new-password" placeholder="Old Password">
                                        <span class="input-group-btn">
                                            <button type="submit" class="btn btn-raised btn-primary btn-flat"><i class="fa fa-save"></i> Save Changes</button>
                                        </span>
                                    </div>
                                </div>
                                <div class="clearfix"></div>
                            </form>

                            <div class="clearfix"></div>
                        </div>

                        <div class="tab-pane" id="api">
                            <?php if ($api_credentials): ?>
                                <h4 class="lead"> <i class="fa fa-lock"></i> API Credentials</h4>
                                <table class="table table-bordered">
                                    <tr>
                                        <td>Client ID</td>
                                        <td><code><?= $api_credentials['client_id'] ?></code></td>
                                    </tr>

                                    <tr>
                                        <td>Client Secret</td>
                                        <td><code><?= $api_credentials['client_secret'] ?></code></td>
                                    </tr>
                                </table>
                                <p> &nbsp; </p>
                            <?php endif; ?>
                        </div>
                        <!-- /.tab-pane -->
                        <div class="tab-pane" id="data">
                            <form method="post" action="">
                                <h4 class="lead"> <i class="fa fa-trash"></i> Account Deletion</h4>
                                <div class="alert alert-danger">
                                    <strong>Warning!</strong> This action cannot be undone. All your data, including surveys, runs, and email accounts will be permanently deleted.
                                </div>

                                <div class="form-group">
                                    <label class="control-label" for="delete_confirm">Type "I understand my data will be gone"</label>
                                    <input class="form-control" type="text" id="delete_confirm" name="delete_confirm" required
                                        placeholder="I understand my data will be gone" autocomplete="off">
                                </div>

                                <div class="form-group">
                                    <label class="control-label" for="delete_email">Type your current email address</label>
                                    <input class="form-control" type="text" id="delete_email" name="delete_email" required
                                        placeholder="<?= h($user->email) ?>" autocomplete="off">
                                </div>

                                <div class="form-group">
                                    <label class="control-label" for="delete_password">Current Password</label>
                                    <input class="form-control" type="password" id="delete_password" name="delete_password" required autocomplete="current-password">
                                </div>

                                <?php if ($user->is2FAenabled()): ?>
                                    <div class="form-group">
                                        <label class="control-label" for="delete_2fa">Two-Factor Authentication Code</label>
                                        <input class="form-control" type="text" id="delete_2fa" name="delete_2fa" required placeholder="Enter your 2FA code" autocomplete="one-time-code">
                                    </div>
                                <?php endif; ?>


                                <div class="form-group">
                                    <button type="submit" name="delete_account" value="true" class="btn btn-danger btn-raised">
                                        <i class="fa fa-trash"></i> Permanently Delete Account
                                    </button>
                                </div>
                            </form>
                        </div>
                        <!-- /.tab-pane -->
                        <div class="tab-pane" id="2fa">

                            <h4 class="lead"> <i class="fa fa-lock"></i> Login security</h4>
                            <?php if (!Config::get('2fa.enabled', true)): ?>
                                <div class="alert alert-info">
                                    <i class="fa fa-info-circle"></i> Two-factor authentication is not enabled on this instance.
                                </div>
                            <?php elseif ($user->is2FAenabled()): ?>
                                <div class="alert alert-success">
                                    <i class="fa fa-check-circle"></i> Two-factor authentication is enabled for your account.
                                </div>
                                <div class="form-group col-md-6">
                                    <a href="<?= admin_url('account/manage-two-factor') ?>" class="btn btn-raised btn-warning">
                                        <i class="fa fa-cog"></i> Manage 2FA Settings
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="form-group col-md-6">
                                    <div class="alert alert-warning">
                                        <i class="fa fa-warning"></i> Two-factor authentication is not enabled for your account. Enable it to add an extra layer of security.
                                    </div>
                                    <p>
                                        Two-factor authentication adds an extra layer of security to your account.
                                        Once enabled, you'll need both your password and a code from your authenticator app to log in.
                                    </p>
                                </div>
                                <div class="form-group col-md-12">
                                    <a href="<?= admin_url('account/setup-two-factor') ?>" class="btn btn-raised btn-primary">
                                        <i class="fa fa-lock"></i> Setup 2FA
                                    </a>
                                </div>
                            <?php endif; ?>

                            <div class="clearfix"></div>
                        </div>
                        <?php if ($user->isAdmin()): ?>
                        <div class="tab-pane" id="ai">
                            <form method="post" action="">
                                <input type="hidden" name="save_ai_settings" value="1">
                                <input type="hidden" name="<?= Session::REQUEST_TOKENS ?>" value="<?= Session::getRequestToken() ?>">

                                <h4 class="lead"><i class="fa fa-cog"></i> AI Integration</h4>

                                <div class="form-group">
                                    <div class="checkbox">
                                        <label>
                                            <input type="checkbox" name="ai_enabled" value="1" <?= !empty($ai_config['enabled']) && $ai_config['enabled'] !== '0' ? 'checked' : '' ?>>
                                            AI feature enabled (allows participants and researchers to call the AI API)
                                        </label>
                                    </div>
                                </div>

                                <div class="form-group col-md-4">
                                    <label class="control-label">Provider</label>
                                    <select class="form-control" name="ai_provider">
                                        <option value="claude"  <?= array_val($ai_config, 'provider', 'claude') === 'claude'  ? 'selected' : '' ?>>Anthropic Claude</option>
                                        <option value="openai"  <?= array_val($ai_config, 'provider', 'claude') === 'openai'  ? 'selected' : '' ?>>OpenAI</option>
                                    </select>
                                </div>
                                <div class="clearfix"></div>

                                <h4 class="lead"><i class="fa fa-key"></i> Anthropic Claude</h4>

                                <div class="form-group col-md-8">
                                    <label class="control-label">Claude API Key</label>
                                    <input class="form-control" type="password" name="ai_claude_api_key"
                                        value="<?= h(array_val($ai_config, 'claude_api_key', '')) ?>"
                                        autocomplete="new-password" placeholder="sk-ant-…">
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="control-label">Claude Model</label>
                                    <select class="form-control" name="ai_claude_model">
                                        <?php foreach (array('claude-sonnet-4-6','claude-opus-4-6','claude-haiku-4-5-20251001') as $m): ?>
                                        <option value="<?= h($m) ?>" <?= array_val($ai_config, 'claude_model', 'claude-sonnet-4-6') === $m ? 'selected' : '' ?>><?= h($m) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="clearfix"></div>

                                <h4 class="lead"><i class="fa fa-key"></i> OpenAI</h4>

                                <div class="form-group col-md-8">
                                    <label class="control-label">OpenAI API Key</label>
                                    <input class="form-control" type="password" name="ai_openai_api_key"
                                        value="<?= h(array_val($ai_config, 'openai_api_key', '')) ?>"
                                        autocomplete="new-password" placeholder="sk-…">
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="control-label">OpenAI Model</label>
                                    <select class="form-control" name="ai_openai_model">
                                        <?php foreach (array('gpt-4o','gpt-4o-mini','gpt-4-turbo','gpt-3.5-turbo') as $m): ?>
                                        <option value="<?= h($m) ?>" <?= array_val($ai_config, 'openai_model', 'gpt-4o') === $m ? 'selected' : '' ?>><?= h($m) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="clearfix"></div>

                                <h4 class="lead"><i class="fa fa-sliders"></i> Limits &amp; Performance</h4>

                                <div class="form-group col-md-3">
                                    <label class="control-label">Max Tokens per Response</label>
                                    <input class="form-control" type="number" name="ai_max_tokens" min="64" max="4096"
                                        value="<?= (int) array_val($ai_config, 'max_tokens', 1024) ?>">
                                </div>
                                <div class="form-group col-md-3">
                                    <label class="control-label">Timeout (seconds)</label>
                                    <input class="form-control" type="number" name="ai_timeout_seconds" min="5" max="300"
                                        value="<?= (int) array_val($ai_config, 'timeout_seconds', 60) ?>">
                                </div>
                                <div class="clearfix"></div>

                                <h4 class="lead"><i class="fa fa-comments"></i> Chat Defaults (per item, 0 = unlimited/no limit)</h4>

                                <div class="form-group col-md-3">
                                    <label class="control-label">Min Prompts (Turns)</label>
                                    <input class="form-control" type="number" name="ai_min_turns" min="0"
                                        value="<?= (int) array_val($ai_config, 'min_turns', 3) ?>"
                                        title="Minimum number of exchanges before the participant can proceed">
                                </div>
                                <div class="form-group col-md-3">
                                    <label class="control-label">Max Prompts (Turns)</label>
                                    <input class="form-control" type="number" name="ai_max_turns" min="0"
                                        value="<?= (int) array_val($ai_config, 'max_turns', 0) ?>"
                                        title="Maximum number of exchanges allowed (0 = unlimited)">
                                </div>
                                <div class="form-group col-md-3">
                                    <label class="control-label">Min Words per Prompt</label>
                                    <input class="form-control" type="number" name="ai_min_words" min="0"
                                        value="<?= (int) array_val($ai_config, 'min_words', 0) ?>"
                                        title="Minimum number of words a participant must write per message (0 = no minimum)">
                                </div>
                                <div class="form-group col-md-3">
                                    <label class="control-label">Max Words per Prompt</label>
                                    <input class="form-control" type="number" name="ai_max_words" min="0"
                                        value="<?= (int) array_val($ai_config, 'max_words', 0) ?>"
                                        title="Maximum number of words a participant may write per message (0 = unlimited)">
                                </div>
                                <div class="clearfix"></div>

                                <h4 class="lead"><i class="fa fa-tachometer"></i> Rate Limits (per researcher, 0 = unlimited)</h4>

                                <div class="form-group col-md-3">
                                    <label class="control-label">Calls per Hour</label>
                                    <input class="form-control" type="number" name="ai_calls_per_hour" min="0"
                                        value="<?= (int) array_val($ai_config, 'calls_per_hour', 20) ?>">
                                </div>
                                <div class="form-group col-md-3">
                                    <label class="control-label">Calls per Day</label>
                                    <input class="form-control" type="number" name="ai_calls_per_day" min="0"
                                        value="<?= (int) array_val($ai_config, 'calls_per_day', 100) ?>">
                                </div>
                                <div class="form-group col-md-3">
                                    <label class="control-label">Daily Output Token Limit</label>
                                    <input class="form-control" type="number" name="ai_daily_token_limit" min="0"
                                        value="<?= (int) array_val($ai_config, 'daily_token_limit', 0) ?>">
                                </div>
                                <div class="clearfix"></div>

                                <div class="form-group col-md-12">
                                    <button type="submit" class="btn btn-raised btn-primary btn-flat">
                                        <i class="fa fa-save"></i> Save AI Settings
                                    </button>
                                </div>
                                <div class="clearfix"></div>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <!-- /.tab-content -->
                </div>

            </div>
        </div>

    </section>


</div>

<?php Template::loadChild('admin/footer'); ?>