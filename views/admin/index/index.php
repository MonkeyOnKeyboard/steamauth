<form method="POST" action="<?= $this->getUrl(['action' => 'save']) ?>">
    <legend><?=$this->getTrans('steamauth.settings') ?></legend>
    <div class="alert alert-info">
        <?= $this->getTrans('steamauth.getyourkeys', '<a href="https://steamcommunity.com/dev/apikey" target="_blank">https://steamcommunity.com/dev/apikey</a>') ?>
    </div>
    <?=$this->getTokenField() ?>
    <div class="row-mb-3">
        <label for="consumerKeyInput" class="col-lg-2 control-label">
            <?=$this->getTrans('steamauth.consumerkey') ?>:
        </label>
        <div class="col-lg-10">
            <input type="text"
                   class="form-control"
                   id="consumerKeyInput"
                   name="consumerKey"
                   value="<?=$this->escape($this->get('steamauth')['consumerKey']) ?>" />
        </div>
    </div>
    <?=$this->getSaveBar() ?>
</form>
