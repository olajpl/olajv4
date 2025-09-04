<div class="w-full px-4 mt-8" x-data="{
  subTab: 'clients',
  clients: [
    {
      id: 1,
      selected: 'Basia K.',
      options: ['Basia K.', 'Ania R.', 'Zdzisław F.'],
      products: [
        { id: 101, name: 'Zestaw noży', qty: 2, price: '99.00' },
        { id: 102, name: 'Poduszka', qty: 1, price: '49.00' }
      ]
    },
    {
      id: 2,
      selected: 'Marek L.',
      options: ['Marek L.', 'Janek S.'],
      products: [
        { id: 103, name: 'Ręcznik', qty: 3, price: '19.90' }
      ]
    }
  ],
  products: [
    {
      id: 201,
      name: 'Zestaw noży',
      clients: [
        { id: 1, name: 'Basia K.', qty: 2 },
        { id: 2, name: 'Marek L.', qty: 1 }
      ]
    },
    {
      id: 202,
      name: 'Poduszka',
      clients: [
        { id: 1, name: 'Basia K.', qty: 1 }
      ]
    },
    {
      id: 203,
      name: 'Ręcznik',
      clients: [
        { id: 2, name: 'Marek L.', qty: 3 }
      ]
    }
  ]
}">
  <div class="bg-white border rounded-lg shadow p-4">
    <div class="border-b mb-4 flex space-x-4">
      <button class="py-2 px-3 font-medium text-sm" :class="{ 'border-b-2 border-green-500 text-green-700': subTab==='clients' }" @click="subTab='clients'">Klienci</button>
      <button class="py-2 px-3 font-medium text-sm" :class="{ 'border-b-2 border-green-500 text-green-700': subTab==='products' }" @click="subTab='products'">Produkty</button>
    </div>

    <template x-if="subTab==='clients'">
      <div class="space-y-4">
        <template x-for="client in clients" :key="client.id">
          <div class="border rounded p-3">
            <div class="flex justify-between items-center mb-2">
              <select x-model="client.selected" class="border rounded px-2 py-1 w-full max-w-[80%]">
                <template x-for="name in client.options" :key="name">
                  <option x-text="name"></option>
                </template>
              </select>
              <button class="text-red-600 hover:text-red-800 text-xs ml-2" @click="clients = clients.filter(c => c.id !== client.id)">Usuń klienta</button>
            </div>
            <ul class="list-disc pl-5 text-xs text-gray-700">
              <template x-for="(product, pindex) in client.products" :key="product.id">
                <li class="flex justify-between items-center">
                  <span><span x-text="product.name"></span> – <span x-text="product.qty"></span> szt., <span x-text="product.price"></span> zł</span>
                  <button class="text-red-500 hover:text-red-700 text-xs" @click="client.products.splice(pindex, 1)">Usuń produkt</button>
                </li>
              </template>
            </ul>
          </div>
        </template>

        <div class="mt-6">
          <h4 class="text-sm font-semibold mb-2">Lista klientów</h4>
          <table class="w-full text-xs text-left border-t">
            <thead>
              <tr class="text-gray-500">
                <th class="py-2">Klient</th>
                <th>Ilość produktów</th>
                <th>Łączna wartość</th>
              </tr>
            </thead>
            <tbody>
              <template x-for="client in clients" :key="'summary-'+client.id">
                <tr class="border-t">
                  <td class="py-1" x-text="client.selected"></td>
                  <td class="py-1" x-text="client.products.length"></td>
                  <td class="py-1" x-text="client.products.reduce((t, p) => t + parseFloat(p.price || 0) * parseInt(p.qty || 1), 0).toFixed(2) + ' zł'"></td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <template x-if="subTab==='products'">
      <div class="space-y-4">
        <template x-for="product in products" :key="product.id">
          <div class="border rounded p-3">
            <div class="flex justify-between items-center mb-2">
              <div class="font-semibold text-sm" x-text="product.name"></div>
              <button class="text-red-600 hover:text-red-800 text-xs ml-2" @click="products = products.filter(p => p.id !== product.id)">Usuń produkt</button>
            </div>
            <ul class="list-disc pl-5 text-xs text-gray-700">
              <template x-for="client in product.clients" :key="client.id">
                <li><span x-text="client.name"></span> – <span x-text="client.qty"></span> szt.</li>
              </template>
            </ul>
          </div>
        </template>
      </div>
    </template>
  </div>
</div>
