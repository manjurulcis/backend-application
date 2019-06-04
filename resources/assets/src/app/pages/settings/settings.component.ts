import {Component, ViewChild, AfterViewInit, OnDestroy} from '@angular/core';
import {TabsetComponent, TabDirective} from 'ngx-bootstrap';
import {Message} from 'primeng/components/common/api';

import {ApiService} from '../../api/api.service';

import {GeneralComponent} from './tabs/settings.tabs.general.component';
import {IntegrationRedmineComponent} from './tabs/settings.tabs.integration-redmine.component';
import {IntegrationGitlabComponent} from './tabs/settings.tabs.integration-gitlab.component';
import {UserSettingsComponent} from './tabs/settings.tabs.user.component';

@Component({
    selector: 'app-settings',
    templateUrl: './settings.component.html',
    styleUrls: ['./settings.component.css']
})
export class SettingsComponent implements AfterViewInit, OnDestroy {
    @ViewChild('tabs') tabs: TabsetComponent;
    @ViewChild('general') general: GeneralComponent;
    @ViewChild('integrationRedmine') integrationRedmine: IntegrationRedmineComponent;
    @ViewChild('integrationGitlab') integrationGitlab: IntegrationGitlabComponent;
    @ViewChild('userSettings') userSettings: UserSettingsComponent;

    selectedTab = '';
    msgs: Message[] = [];

    constructor(protected api: ApiService) {
    }


    ngAfterViewInit() {
        const tabHeading = localStorage.getItem('settings-tab');
        if (tabHeading !== null) {
            const index = this.tabs.tabs.findIndex(tab => tab.heading === tabHeading);
            if (index !== -1) {
                setTimeout(() => {
                    this.selectedTab = tabHeading;
                    this.tabs.tabs[index].active = true;
                });
            }
        }
    }

    changeTab(tab: TabDirective) {
        if (tab.heading !== undefined) {
            this.selectedTab = tab.heading;
            localStorage.setItem('settings-tab', this.selectedTab);
        }
    }

    onMessage(message: Message) {
        this.msgs = [message];
    }


    cleanupParams(): string[] {
        return [
            'tabs',
            'general',
            'integrationRedmine',
            'integrationGitlab',
            'userSettings',
            'selectedTab',
            'msgs',
            'api',
        ];
    }

    ngOnDestroy() {
        for (const param of this.cleanupParams()) {
            delete this[param];
        }
    }
}
