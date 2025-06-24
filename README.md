<p align="center"><img src="assets/images/logo.svg" alt="Logo"></p>
<h3 align="center">KRRU LAN-Party Management System</h3>

CMS (Content management system) for LAN-Parties. Everything you need to present and manage your event in a modern design.

## Features

KLMS helps you with
 - **Present your event** With news-posts, content pages, and team-sites, you can present your event in an appealing and modern design. Dynamic navigation allows you to structure your site however _you_ want.
 - **Registration, Payment, and Check-In Support** Keep to date with who is attending your event.
 - **Seatmap** Create a seatmap for your event. Your guests can choose their favorite seat.
 - **Tournament System** Keep track of your tournaments. KLMS got you covered with all steps including registration, seeding, and result tracking. To keep the overview, the process is presented in tournament trees.
 - **Community Management** Who else is attending your event? Who is your audience? KLMS allows you to store your user-base in a central location for all your events.
 - **E-Mail Newsletter** Keep your audience up-to-date with Newsletters. Send E-Mails to all your customers or specific groups.

KLMS is
 - **modern** Using up-to-date technology stacks (Symfony and Bootstrap).
 - **open source** Licensed under [GPLv3](LICENSE).
 - **actively maintained** and in use for multiple events.
 - currently German only. Internationalisation support including an English translation is coming up.

## Setup
Setup instructions and system requirements can be found in our [setup documentation](SETUP.md).

### Run website
Once all setup steps are done start the Symfony development server using
```
XDEBUG_MODE=debug symfony server:start --port=8002 --no-tls
```
Open the printed URL in your browser and log in with a superuser credential 

### Database updates
Schema changes have to applied via:
```bash
php bin/console doctrine:schema:update --force --complete
php bin/console doctrine:schema:validate
```

### Debugging
```bash
# on kaiserlan plesk bash-4.4$ /.phpenv/versions/8.3/bin/php bin/console cache:clear

php bin/console debug:container --env-vars
php bin/console debug:dotenv

# clear cache
php -d memory_limit=1024M bin/console cache:clear

# clear doctrine cache
php bin/console doctrine:cache:clear-metadata 
php bin/console doctrine:cache:clear-query  
php bin/console doctrine:cache:clear-result

# debugging if datebase connection has issues (.env not picked up)
symfony server:stop
symfony server:reset
php bin/console doctrine:query:sql "SELECT 1"
# then it suddenly worked

# debug routes
php bin/console debug:router | grep -i catering

```

## Hidden Features

seatmap print view: /seatmap?print=1  .. then zoom in, take a screenshot and it gets printed in full paper size