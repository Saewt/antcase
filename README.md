# Glossary Auto Link

WordPress içeriklerinde geçen sözlük terimlerini otomatik olarak ilgili glossary sayfalarına linkleyen eklenti.

## Test Etme

WordPress kurulumu gerektirmez; bağımsız PHP ortamında repo kökünden çalıştırın:

```bash
php glossary-auto-link/test-glossary.php
```

Tüm testler geçtiğinde:

```
[PASS] Test 1: registers a the_content filter
[PASS] Test 2: links a glossary term with the required URL format
[PASS] Test 3: only changes text nodes, not HTML attributes
[PASS] Test 4: does not change already linked text
[PASS] Test 5: prefers Asphalt Shingle over the nested Shingle term
[PASS] Test 6: matches case-insensitively and preserves original casing
[PASS] Test 7: preserves the DOM structure of multiple sibling elements
[PASS] Test 8: does not add wrappers around plain text content

=============================
Results: 8 passed, 0 failed out of 8 tests.
=============================
```

## Edge Case Çözümleri

| Edge Case | Çözüm |
|-----------|--------|
| HTML etiket içi attribute'lerin değişmesi | DOMDocument ile parse edilir, sadece `DOMText` node'ları işlenir |
| Zaten linklenmiş metnin tekrar linklenmesi | `<a>` etiketleri `$skip_tags` ile atlanır |
| Çakışan terimler (Asphalt Shingle vs Shingle) | Terimler önce uzunluk bazında sıralanır, eşleşmeler offset kontrolü ile elenir |
| Büyük/küçük harf varyasyonları | Regex `/iu` flag ile case-insensitive eşleşme, `$match[0]` ile orijinal casing korunur |
| Plain text girdiye wrapper eklenmesi | Geçici `<div id="glossary-wrapper">` kullanılır, işlem sonrası kaldırılır |
| Çoklu root element (`<p>A</p><p>B</p>`) | Wrapper div + `childNodes` iterasyonu ile yapı korunur |

## Yapay Zeka Kullanım Raporu

Bu çalışma sırasında prompt geçmişinin linki ya da ekran görüntüsü ayrıca kaydedilmedi. O yüzden burada süreci, gerçekten nasıl ilerlediğini anlatacak şekilde not düşüyorum.

İlk aşamada case dokümanındaki gereksinimleri ve kendi notlarımı kullanarak AI'dan küçük bir WordPress glossary auto-link eklentisi üretmesini istedim. Temel beklenti belliydi: eklenti `the_content` hook'una bağlanacaktı, glossary verisi hardcoded tutulacaktı, linkler `/glossary/{slug}` formatında üretilecekti, mevcut HTML yapısı bozulmayacaktı, zaten linklenmiş alanlara tekrar dokunulmayacaktı, `Asphalt Shingle` ile `Shingle` çakıştığında uzun eşleşme seçilecekti ve case-insensitive eşleşme yapılırken görünen metin olduğu gibi korunacaktı.

İlk çıkan kod tamamen işe yaramaz değildi. Ana iskeleti kurdu, filtre kaydını yaptı ve temel linkleme mantığını oluşturdu. Ama ilk bakışta temiz görünse de edge case tarafında problem vardı.

En net kırıldığı yer HTML bütünlüğünü koruma kısmı oldu. Özellikle şu örnekte:

```html
<p>Flashing</p><p>Shingle</p>
```

ilk çözüm paragraf yapısını bozup buna benzer bir çıktı üretebiliyordu:

```html
<p><a href="/glossary/roof-flashing">Flashing</a><p><a href="/glossary/shingle-nedir">Shingle</a></p></p>
```

Bu durum `case.txt` içindeki "HTML bütünlüğünü koruma" beklentisini karşılamıyordu. Yani terimler linkleniyor gibi görünse de, çıktıdaki DOM yapısı bozuluyordu. Buna ek olarak plain text bir içerikte DOMDocument bazen metni kendiliğinden `<p>` içine alabiliyordu. Kısacası ilk versiyondaki asıl sorun link üretmek değil, HTML fragment'ı koruyamamaktı.

Bu noktadan sonra işi tamamen regex ile götürmenin doğru olmayacağı netleşti. Çünkü burada mesele sadece kelime bulup değiştirmek değildi; sadece text node'ları işlemek, attribute değerlerine dokunmamak, mevcut linklerin içeriğini bozmamak ve genel DOM yapısını olduğu gibi korumak gerekiyordu. O yüzden çözümü saf regex veya `str_replace()` üstüne kurmak yerine DOMDocument tarafında tutmaya karar verdim.

Sonraki iterasyonda AI'a bunu daha açık anlattım. HTML parse etme işini DOMDocument ile yapmaya devam ettik, ama text node içindeki eşleşmeleri bulmak için regex kullandık. Yani ortaya karma bir çözüm çıktı. İçerik önce geçici bir wrapper içine alındı, sonra sadece `DOMText` node'ları gezildi. `<a>`, `<script>`, `<style>`, `<code>` ve `<pre>` gibi alanlar tamamen atlandı. Çakışan terimlerde de offset bazlı kontrol ile en uzun eşleşme önceliği korundu. İşin sonunda wrapper kaldırılıp sadece orijinal içeriğin HTML'i döndürüldü.

Kod bu hale geldikten sonra bağımsız PHP testleri yazıldı. İlk testler temel davranışı ölçüyordu ama review sırasında bunların bazı hataları kaçırabilecek kadar yüzeysel olduğu fark edildi. Özellikle bozuk DOM çıktılarının bazı kontrollerden geçebileceği görüldü. Bu yüzden testler ikinci kez elden geçirildi ve daha sıkı hale getirildi.

Son durumda testler şunları doğrudan kontrol edecek şekilde yazıldı: `the_content` filtresinin gerçekten register edilmesi, `/glossary/{slug}` formatında link üretimi, attribute değerlerinin korunması, mevcut `<a>` içinin atlanması, `Asphalt Shingle` ve `Shingle` çakışmasında uzun eşleşmenin seçilmesi, case-insensitive eşleşmede orijinal casing'in korunması, çoklu sibling root yapısının bozulmaması ve plain text içeriğe gereksiz wrapper eklenmemesi.
