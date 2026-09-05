<?php

namespace App\Tests\Functional\Admin;

use App\DataFixtures\SeatmapFixture;
use App\Tests\Functional\DatabaseWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

class PlatzkartenTest extends DatabaseWebTestCase
{
    public function testEverySeatGetsACard(): void
    {
        $this->databaseTool->loadFixtures([SeatmapFixture::class]);
        $this->login('admin@localhost.local');

        $crawler = $this->client->request('GET', '/admin/seatmap/platzkarten');
        $this->assertResponseStatusCodeSame(200);

        // L-1 bis L-3 sind belegt, X-2/X-3 und Y-1 bis Y-3 frei.
        // X-1 ist gesperrt und Z-1 nur ein Info-Marker, beide sind keine Sitzplätze.
        $this->assertSame(
            ['L-1', 'L-2', 'L-3', 'X-2', 'X-3', 'Y-1', 'Y-2', 'Y-3'],
            $this->values($crawler, '.card__value--platz'),
        );
        $this->assertSame(
            ['User 1', 'User 2', 'User 3', '', '', '', '', ''],
            $this->values($crawler, '.card__value:not(.card__value--platz)'),
        );
    }

    public function testSectorFilterKeepsFreeSeats(): void
    {
        $this->databaseTool->loadFixtures([SeatmapFixture::class]);
        $this->login('admin@localhost.local');

        $crawler = $this->client->request('GET', '/admin/seatmap/platzkarten?sector=X');
        $this->assertResponseStatusCodeSame(200);

        $this->assertSame(['X-2', 'X-3'], $this->values($crawler, '.card__value--platz'));
        $this->assertSame(['', ''], $this->values($crawler, '.card__value:not(.card__value--platz)'));
    }

    /**
     * @return string[] Text of all matching nodes, with the placeholder space of nameless cards removed
     */
    private function values(Crawler $crawler, string $selector): array
    {
        return $crawler->filter($selector)->each(
            static fn (Crawler $node) => trim(str_replace("\u{A0}", '', $node->text())),
        );
    }
}
