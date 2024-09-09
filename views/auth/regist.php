<link href="<?=$this->getModuleUrl('static/css/steam.css') ?>" rel="stylesheet">

<form method="POST" action="<?= $this->getUrl(['action' => 'save']) ?>" autocomplete="off">
    <legend><i class="fa-brands fa-square-steam steamBlue"></i> <?=$this->getTrans('steamauth.steamauth') ?></legend>
    <div class="card card-default">
        <div class="bg-info card-body">
            <?= $this->getTrans('steamauth.passwordandemailneeded') ?>
        </div>
        <div class="card-body">
            <?=$this->getTokenField() ?>
            <div class="row-mb-3 <?= ! $this->validation()->hasError('userName') ?: 'has-error' ?>">
                <label for="userNameInput" class="col-xl-3 col-form-label">
                    <?=$this->getTrans('steamauth.username') ?>:
                </label>
                <div class="col-xl-9">
                    <input type="text"
                           class="form-control"
                           id="userNameInput"
                           name="userName"
                           value="<?= $this->originalInput('userName', $this->get('user')['screen_name']) ?>" />
                </div>
            </div>
            <div class="row-mb-3 <?= ! $this->validation()->hasError('email') ?: 'has-error' ?>">
                <label for="emailInput" class="col-xl-3 col-form-label">
                    <?=$this->getTrans('steamauth.email') ?>:
                </label>
                <div class="col-xl-9">
                    <input type="email"
                           class="form-control"
                           id="emailInput"
                           name="email"
                           value="<?= $this->originalInput('email') ?>" />
                </div>
            </div>
        </div>
        <div class="card-body">
            <?= $this->get('rules') ?>
        </div>
        <div class="bg-info card-body">
            <?= $this->getTrans('steamauth.rules') ?>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-arrow-right"></i> <?= $this->getTrans('steamauth.completeregistration') ?></button>
            <a href="#" class="btn btn-default"><?= $this->getTrans('steamauth.cancel') ?></a>
        </div>
    </div>
</form>
