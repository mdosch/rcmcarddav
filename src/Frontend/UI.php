<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2021 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
 *                         Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of RCMCardDAV.
 *
 * RCMCardDAV is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * RCMCardDAV is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with RCMCardDAV. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube\Frontend;

use MStilkerich\CardDavClient\Account;
use MStilkerich\CardDavAddressbook4Roundcube\Config;
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;

/**
 * @psalm-type UiFieldType = 'text'|'plain'|'datetime'|'timestr'|'radio'|'password'
 * @psalm-type SettingFieldSpec = array{0: string, 1: string, 2: UiFieldType, 3?: list<array{string,string}>}
 * @psalm-type FieldSetSpec = array{label: string, fields: list<SettingFieldSpec>}
 * @psalm-type FormSpec = list<FieldSetSpec>
 * @psalm-import-type FullAbookRow from AbstractDatabase
 * @psalm-import-type FullAccountRow from AbstractDatabase
 * @psalm-import-type AbookSettings from AddressbookManager
 */
class UI
{
    /** @var FormSpec UI_FORM_ACCOUNT */
    private const UI_FORM_ACCOUNT = [
        [
            'label' => 'basicinfo',
            'fields' => [
                [ 'accountname', 'name', 'text' ],
                [ 'discoveryurl', 'url', 'plain' ],
                [ 'cd_username', 'username', 'text' ],
                [ 'cd_password', 'password', 'password' ],
            ]
        ],
        [
            'label' => 'discoveryinfo',
            'fields' => [
                [ 'rediscover_time', 'rediscover_time', 'timestr' ],
                [ 'lastdiscovered_time', 'last_discovered', 'datetime' ],
            ]
        ],
    ];

    /** @var FormSpec UI_FORM_ABOOK */
    private const UI_FORM_ABOOK = [
        [
            'label' => 'basicinfo',
            'fields' => [
                [ 'cd_name', 'name', 'text' ],
                [ 'cd_url', 'url', 'plain' ],
            ]
        ],
        [
            'label' => 'syncinfo',
            'fields' => [
                [ 'cd_refresh_time', 'refresh_time', 'timestr' ],
                [ 'cd_lastupdate_time', 'last_updated', 'datetime' ],
            ]
        ],
        [
            'label' => 'miscsettings',
            'fields' => [
                [
                    'newgroupstype',
                    'use_categories',
                    'radio',
                    [
                        [ '0', 'grouptype_vcard' ],
                        [ '1', 'grouptype_categories' ],
                    ]
                ],
            ]
        ],
    ];

    /**
     * The addressbook manager.
     * @var AddressbookManager
     */
    private $abMgr;

    /**
     * Constructs a new UI object.
     *
     * @param AddressbookManager $abMgr The AddressbookManager to use.
     */
    public function __construct(AddressbookManager $abMgr)
    {
        $this->abMgr = $abMgr;

        $infra = Config::inst();
        $rc = $infra->rc();

        $rc->addHook('settings_actions', [$this, 'addSettingsAction']);

        $rc->registerAction('plugin.carddav', [$this, 'renderAddressbookList']);
        $rc->registerAction('plugin.carddav.activateabook', [$this, 'actionChangeAddressbookActive']);
        $rc->registerAction('plugin.carddav.abookdetails', [$this, 'actionAddressbookDetails']);
        $rc->registerAction('plugin.carddav.accountdetails', [$this, 'actionAccountDetails']);
        $rc->includeCSS('carddav.css');
        $rc->includeJS("carddav.js");
    }

    /**
     * Adds a carddav section in settings.
     * @psalm-param array{actions: array} $args
     */
    public function addSettingsAction(array $args): array
    {
        // register as settings action
        $args['actions'][] = [
            'action' => 'plugin.carddav',
            'class'  => 'cd_preferences', // CSS style
            'label'  => 'cd_title', // text display
            'title'  => 'cd_title', // tooltip text
            'domain' => 'carddav',
        ];

        return $args;
    }

    public function renderAddressbookList(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $rc->setPagetitle($rc->locText('cd_title'));

        $rc->includeJS('treelist.js', true);
        $rc->addTemplateObjHandler('addressbookslist', [$this, 'tmplAddressbooksList']);
        $rc->sendTemplate('carddav.addressbooks');
    }

    /**
     * Template object for list of addressbooks.
     *
     * @psalm-param array{id?: string} $attrib
     * @param array $attrib Object attributes
     *
     * @return string HTML content
     */
    public function tmplAddressbooksList(array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        if (empty($attrib['id'])) {
            $attrib['id'] = 'rcmcarddavaddressbookslist';
        }

        $abMgr = $this->abMgr;
        $accountIds = $abMgr->getAccountIds();

        $accounts = [];
        foreach ($accountIds as $accountId) {
            $accounts[$accountId] = $abMgr->getAccountConfig($accountId);
            $accounts[$accountId]['addressbooks'] = $abMgr->getAddressbookConfigsForAccount($accountId);
        }

        $checkboxActive = new \html_checkbox([
                'name'    => '_active[]',
                'title'   => $rc->locText('changeactive'),
                'onclick' => \rcmail_output::JS_OBJECT_NAME .
                  ".command('plugin.carddav-toggle-abook-active', {abookid: this.value, state: this.checked})",
        ]);

        $accountListItems = [];
        foreach ($accounts as $account) {
            $content = \html::a(['href' => '#'], \rcube::Q($account["name"]));

            $addressbookListItems = [];
            foreach (($account["addressbooks"] ?? []) as $abook) {
                $attribs = [
                    'id'    => 'rcmli_abook' . $abook["id"],
                    'class' => 'addressbook'
                ];

                $abookHtml = \html::a(['href' => '#'], \rcube::Q($abook["name"]));
                $abookHtml .= $checkboxActive->show($abook["active"] ? $abook['id'] : '', ['value' => $abook['id']]);
                $addressbookListItems[] = \html::tag('li', $attribs, $abookHtml);
            }

            if (!empty($addressbookListItems)) {
                $content .= \html::div('treetoggle expanded', '&nbsp;');
                $content .= \html::tag('ul', ['style' => null], implode("\n", $addressbookListItems));
            }

            $attribs = [
                'id'    => 'rcmli_acc' . $account["id"],
                'class' => 'account'
            ];
            $accountListItems[] = \html::tag('li', $attribs, $content);
        }

        $rc->addGuiObject('addressbookslist', $attrib['id']);
        return \html::tag('ul', $attrib, implode('', $accountListItems));
    }

    public function actionChangeAddressbookActive(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $abookId = $rc->inputValue("abookid", false);
        // the state parameter is set to 0 (deactivated) or 1 (active) by the client
        $active  = $rc->inputValue("state", false);

        if (isset($abookId) && isset($active)) {
            try {
                $active = ($active == "1"); // if this is some invalid value, just consider it as deactivated
                $prefix = $active ? "" : "de";
                $this->abMgr->updateAddressbook($abookId, ['active' => $active ]);
                $rc->showMessage($rc->locText("${prefix}activateabook_success"), 'confirmation');
            } catch (\Exception $e) {
                $rc->showMessage("Activation failed!", 'error');
                $rc->clientCommand('carddav_reset_active', $abookId, !$active);
            }
        }
    }

    public function actionAccountDetails(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $accountId = $rc->inputValue("accountid", false, \rcube_utils::INPUT_GET);

        if (isset($accountId)) {
            $rc->addTemplateObjHandler('accountdetails', [$this, 'tmplAccountDetails']);
            $rc->sendTemplate('carddav.accountDetails');
        }
    }

    public function actionAddressbookDetails(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();

        $abookId = $rc->inputValue("abookid", false, \rcube_utils::INPUT_POST);
        if (isset($abookId)) {
            // POST - Settings saved
            try {
                $abMgr = $this->abMgr;
                $abookrow = $abMgr->getAddressbookConfig($abookId);
                $account = $abMgr->getAccountConfig($abookrow["account_id"]);
                $fixedAttributes = $this->getFixedSettings($account['presetname'], $abookrow['url']);
                $newset = $this->getAddressbookSettingsFromPOST(self::UI_FORM_ABOOK, $fixedAttributes);
                $abMgr->updateAddressbook($abookId, $newset);
            } catch (\Exception $e) {
                $logger->error("Error saving carddav preferences: " . $e->getMessage());
            }
        } else {
            // GET - Addressbook selected in list
            $abookId = $rc->inputValue("abookid", false, \rcube_utils::INPUT_GET);
        }

        if (isset($abookId)) {
            $rc->setPagetitle($rc->locText('abookproperties'));
            $rc->addTemplateObjHandler('addressbookdetails', [$this, 'tmplAddressbookDetails']);
            $rc->sendTemplate('carddav.addressbookDetails');
        } else {
            $logger->warning(__METHOD__ . ": no addressbook ID found in parameters");
        }
    }

    /**
     * @param FormSpec $formSpec Specification of the form
     * @param FullAbookRow | FullAccountRow $obj The DB row of the object whose settings shall be shown
     * @param list<string> $fixedAttributes A list of non-changeable settings by choice of the admin
     */
    private function makeSettingsForm(array $formSpec, array $obj, array $fixedAttributes, array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $out = '';
        foreach ($formSpec as $fieldSet) {
            $table = new \html_table(['cols' => 2]);

            foreach ($fieldSet['fields'] as $fieldSpec) {
                [ $fieldLabel, $fieldKey ] = $fieldSpec;

                $readonly = in_array($fieldKey, $fixedAttributes);
                $table->add(['class' => 'title'], \html::label(['for' => $fieldKey], $rc->locText($fieldLabel)));
                $table->add([], $this->uiField($fieldSpec, (string) $obj[$fieldKey], $readonly));
            }

            $out .= \html::tag(
                'fieldset',
                [],
                \html::tag('legend', [], $rc->locText($fieldSet['label'])) . $table->show($attrib)
            );
        }

        return $out;
    }

    /**
     * @return list<string> The list of fixed attributes
     */
    private function getFixedSettings(?string $presetName, ?string $abookUrl = null): array
    {
        if (!isset($presetName)) {
            return [];
        }

        $infra = Config::inst();
        $admPrefs = $infra->admPrefs();
        $preset = $admPrefs->getPreset($presetName, $abookUrl);
        return $preset['fixed'];
    }


    /**
     * @param SettingFieldSpec $fieldSpec
     */
    private function uiField(array $fieldSpec, string $fieldValue, bool $readonly): string
    {
        [, $fieldKey, $uiType ] = $fieldSpec;

        $infra = Config::inst();
        $rc = $infra->rc();

        switch ($uiType) {
            case 'datetime':
                $t = intval($fieldValue);
                if ($t > 0) {
                    $fieldValue = date("Y-m-d H:i:s", intval($fieldValue));
                } else {
                    $fieldValue = $rc->locText('never');
                }
                return \rcube::Q($fieldValue);

            case 'plain':
                return \rcube::Q($fieldValue);

            case 'timestr':
                $t = intval($fieldValue);
                $fieldValue = sprintf("%02d:%02d:%02d", floor($t / 3600), ($t / 60) % 60, $t % 60);
                // fall through to text field

            case 'text':
                $input = new \html_inputfield([
                    'name' => $fieldKey,
                    'type' => $uiType,
                    'value' => $fieldValue,
                    'disabled' => $readonly,
                ]);
                return $input->show();

            case 'password':
                $input = new \html_inputfield([
                    'name' => $fieldKey,
                    'type' => $uiType,
                    'value' => '', // don't pass the password to the UI form
                    'disabled' => $readonly,
                ]);
                return $input->show();

            case 'radio':
                $ul = '';
                $radioBtn = new \html_radiobutton(['name' => $fieldKey]);

                foreach (($fieldSpec[3] ?? []) as $selectionSpec) {
                    [ $selValue, $selLabel ] = $selectionSpec;
                    $ul .= \html::tag(
                        'li',
                        [],
                        $radioBtn->show($fieldValue, ['value' => $selValue]) . $rc->locText($selLabel)
                    );
                }
                return \html::tag('ul', ['class' => 'proplist'], $ul);
        }

        throw new \Exception("Unknown UI element type $uiType for $fieldKey");
    }

    // INFO: name, url, group type, refresh time, time of last refresh, discovered vs. manually added,
    //       cache state (# contacts, groups, etc.), list of custom subtypes (add / delete)
    // ACTIONS: Refresh, Delete (for manually-added addressbooks), Clear local cache
    public function tmplAddressbookDetails(array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();
        $out = '';

        try {
            // Note: abookid is provided as GET (addressbook selection) or POST parameter (settings form)
            $abookId = $rc->inputValue("abookid", false, \rcube_utils::INPUT_GP);
            if (isset($abookId)) {
                $abookrow = $this->abMgr->getAddressbookConfig($abookId);
                $account = $this->abMgr->getAccountConfig($abookrow["account_id"]);

                $fixedAttributes = $this->getFixedSettings($account['presetname'], $abookrow['url']);

                // HIDDEN FIELDS
                $abookIdField = new \html_hiddenfield(['name' => "abookid", 'value' => $abookId]);
                $out .= $abookIdField->show();

                $out .= $this->makeSettingsForm(self::UI_FORM_ABOOK, $abookrow, $fixedAttributes, $attrib);

                $out = $rc->requestForm(
                    [
                        'task' => 'settings',
                        'action' => 'plugin.carddav.abookdetails',
                        'method' => 'post',
                    ] + $attrib,
                    $out
                );
            }
        } catch (\Exception $e) {
            $logger->error($e->getMessage());
        }

        return $out;
    }

    // INFO: name, url, group type, rediscover time, time of last rediscovery
    // ACTIONS: Rediscover, Delete, Add manual addressbook
    public function tmplAccountDetails(array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();
        $out = '';

        try {
            // Note: accountid is provided as GET (account selection) or POST parameter (settings form)
            $accountId = $rc->inputValue("accountid", false, \rcube_utils::INPUT_GP);
            if (isset($accountId)) {
                $account = $this->abMgr->getAccountConfig($accountId);

                $fixedAttributes = $this->getFixedSettings($account['presetname']);

                // HIDDEN FIELDS
                $accountIdField = new \html_hiddenfield(['name' => "accountid", 'value' => $accountId]);
                $out .= $accountIdField->show();

                $out .= $this->makeSettingsForm(self::UI_FORM_ACCOUNT, $account, $fixedAttributes, $attrib);

                $out = $rc->requestForm(
                    [
                        'task' => 'settings',
                        'action' => 'plugin.carddav.accountdetails',
                        'method' => 'post',
                    ] + $attrib,
                    $out
                );
            }
        } catch (\Exception $e) {
            $logger->error($e->getMessage());
        }

        return $out;
    }

    /**
     * This function gets the addressbook settings from a POST request.
     *
     * The result array will only have keys set for POSTed values.
     *
     * For fixed settings of preset addressbooks, no setting values will be contained.
     *
     * @param FormSpec $formSpec Specification of the settings form
     * @param list<string> $fixedAttributes A list of non-changeable settings by choice of the admin
     * @return AbookSettings An array with addressbook column keys and their setting.
     */
    private function getAddressbookSettingsFromPOST(array $formSpec, array $fixedAttributes): array
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $rc = $infra->rc();

        // Fill $result with all values that have been POSTed
        $result = [];
        foreach (array_column($formSpec, 'fields') as $fields) {
            foreach ($fields as $fieldSpec) {
                [ , $fieldKey, $uiType ] = $fieldSpec;

                // Check that the attribute may be overridden
                if (in_array($fieldKey, $fixedAttributes)) {
                    continue;
                }

                $fieldValue = $rc->inputValue($fieldKey, false);
                if (!isset($fieldValue)) {
                    continue;
                }

                // some types require data conversion / validation
                switch ($uiType) {
                    case 'plain':
                    case 'datetime':
                        // These are readonly form elements that cannot be set
                        continue 2;

                    case 'timestr':
                        try {
                            $fieldValue = Utils::parseTimeParameter($fieldValue);
                        } catch (\Exception $e) {
                            // ignore format error, keep old value
                            continue 2;
                        }
                        break;

                    case 'radio':
                        $allowedValues = array_column($fieldSpec[3] ?? [], 0);
                        if (!in_array($fieldValue, $allowedValues)) {
                            // ignore not allowed value
                            $logger->warning("Not allowed value $fieldValue POSTed for $fieldKey (ignored)");
                            continue 2;
                        }
                        break;
                }

                $result[$fieldKey] = $fieldValue;
            }
        }

        /** @psalm-var AbookSettings */
        return $result;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
